<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\PersistentCollection;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Author;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\LazyChild;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\LazyParent;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Tag;

use function array_slice;

final class CollectionInitializationSafetyTest extends AbstractFunctionalTestCase
{
    public function testDeferredAuditMaterializationRecordsFlushedIdsWithoutInitializingExtraLazyCollection(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $parent = new LazyParent('parent');
        $em->persist($parent);
        $em->flush();

        $parentId = $parent->getId();
        self::assertNotNull($parentId);

        $em->clear();

        $parent = $em->find(LazyParent::class, $parentId);
        self::assertInstanceOf(LazyParent::class, $parent);

        $children = $parent->getChildren();
        self::assertInstanceOf(PersistentCollection::class, $children);
        self::assertFalse($children->isInitialized());

        $firstChild = new LazyChild('child-1', $parent);
        $parent->getChildren()->add($firstChild);
        $em->persist($firstChild);
        $em->flush();

        $firstChildId = $firstChild->getId();
        self::assertNotNull($firstChildId);

        $parentAudit = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => LazyParent::class,
            'entityId' => (string) $parentId,
            'action' => AuditAction::Update,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($parentAudit, 'Adding the child through the collection must create a deferred parent audit log.');
        self::assertSame([(string) $firstChildId], $parentAudit->newValues['children'] ?? null);
        self::assertContains('children', $parentAudit->changedFields ?? []);
        self::assertFalse(
            $children->isInitialized(),
            'Deferred audit materialization must not initialize the EXTRA_LAZY collection.',
        );

        foreach (['child-2', 'child-3', 'child-4', 'child-5'] as $name) {
            $em->persist(new LazyChild($name, $parent));
            $em->flush();
        }

        self::assertSame(5, $em->getRepository(LazyChild::class)->count(['parent' => $parent]));
        self::assertCount(
            5,
            $parent->getChildren(),
            'An uninitialized EXTRA_LAZY collection must keep using a fresh database count.',
        );
    }

    public function testDeletedAssociationImpactRecordsChangeWithoutInitializingRelatedCollection(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('author');
        $tags = [];
        foreach (['tag-1', 'tag-2', 'tag-3', 'tag-4', 'tag-5'] as $label) {
            $tag = new Tag($label);
            $author->addTag($tag);
            $tags[] = $tag;
        }

        $em->persist($author);
        $em->flush();

        $authorId = $author->getId();
        self::assertNotNull($authorId);

        $tagIds = [];
        foreach ($tags as $tag) {
            $tagId = $tag->getId();
            self::assertNotNull($tagId);
            $tagIds[] = $tagId;
        }

        $deletedTagId = $tagIds[0];
        $expectedOldIds = array_map('strval', $tagIds);
        $expectedNewIds = array_map('strval', array_slice($tagIds, 1));

        $em->clear();

        $author = $em->find(Author::class, $authorId);
        $deletedTag = $em->find(Tag::class, $deletedTagId);
        self::assertInstanceOf(Author::class, $author);
        self::assertInstanceOf(Tag::class, $deletedTag);

        $authorTags = $author->getTags();
        self::assertInstanceOf(PersistentCollection::class, $authorTags);
        self::assertFalse($authorTags->isInitialized());

        $em->remove($deletedTag);
        $em->flush();

        $authorAudit = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => Author::class,
            'entityId' => (string) $authorId,
            'action' => AuditAction::Update,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($authorAudit, 'Deleting a tag must create an update audit log for the related author.');
        $actualOldIds = $authorAudit->oldValues['tags'] ?? null;
        $actualNewIds = $authorAudit->newValues['tags'] ?? null;
        self::assertIsArray($actualOldIds);
        self::assertIsArray($actualNewIds);
        self::assertEqualsCanonicalizing($expectedOldIds, $actualOldIds);
        self::assertEqualsCanonicalizing($expectedNewIds, $actualNewIds);
        self::assertFalse(
            $authorTags->isInitialized(),
            'Deleted association impact analysis must not initialize the related owner collection.',
        );
        self::assertCount(4, $authorTags);
    }
}
