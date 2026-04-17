<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\UnitOfWork;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;
use stdClass;

final class CollectionChangeResolverTest extends TestCase
{
    public function testBuildCollectionTransitionReturnsOldAndNewIds(): void
    {
        $owner = new stdClass();
        $existingTag = new TestCollectionItem(1);
        $removedTag = new TestCollectionItem(2);
        $addedTag = new TestCollectionItem(3);

        $resolver = $this->createResolver();
        $em = self::createStub(EntityManagerInterface::class);

        $collection = new StubCollection(
            $owner,
            [$addedTag],
            [$removedTag],
            ManyToManyInverseSideMapping::fromMappingArray([
                'fieldName' => 'tags',
                'sourceEntity' => $owner::class,
                'targetEntity' => TestCollectionItem::class,
                'mappedBy' => 'owners',
                'isOwningSide' => false,
            ]),
            [$existingTag, $removedTag],
        );

        self::assertSame([
            'field' => 'tags',
            'old' => ['1', '2'],
            'new' => ['1', '3'],
        ], $resolver->buildCollectionTransition($collection, $em));
    }

    public function testExtractCollectionChangesForOwnerUsesScheduledCollectionUpdates(): void
    {
        $owner = new class {
            /** @var array<int, object> */
            public array $tags = [];
        };
        $existingTag = new TestCollectionItem(1);
        $removedTag = new TestCollectionItem(2);
        $addedTag = new TestCollectionItem(3);
        $owner->tags = [$existingTag, $addedTag];

        $resolver = $this->createResolver();
        $collection = new StubCollection(
            $owner,
            [$addedTag],
            [$removedTag],
            ManyToManyInverseSideMapping::fromMappingArray([
                'fieldName' => 'tags',
                'sourceEntity' => $owner::class,
                'targetEntity' => TestCollectionItem::class,
                'mappedBy' => 'owners',
                'isOwningSide' => false,
            ]),
            [$existingTag, $removedTag],
        );

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getAssociationNames')->willReturn([]);

        $em = self::createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getClassMetadata');

        $uow = self::createMock(UnitOfWork::class);
        $uow->method('getScheduledCollectionUpdates')->willReturn([$collection]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->expects($this->never())->method('getOriginalEntityData');

        self::assertSame([
            ['tags' => ['1', '2']],
            ['tags' => ['1', '3']],
        ], $resolver->extractCollectionChangesForOwner($owner, $em, $uow));
    }

    public function testBuildCollectionTransitionDoesNotDuplicateExistingIds(): void
    {
        $owner = new stdClass();
        $existingTag = new TestCollectionItem(1);
        $addedTag = new TestCollectionItem(1);

        $resolver = $this->createResolver();
        $em = self::createStub(EntityManagerInterface::class);

        $collection = new StubCollection(
            $owner,
            [$addedTag],
            [],
            ManyToManyInverseSideMapping::fromMappingArray([
                'fieldName' => 'tags',
                'sourceEntity' => $owner::class,
                'targetEntity' => TestCollectionItem::class,
                'mappedBy' => 'owners',
                'isOwningSide' => false,
            ]),
            [$existingTag],
        );

        self::assertSame([
            'field' => 'tags',
            'old' => ['1'],
            'new' => ['1'],
        ], $resolver->buildCollectionTransition($collection, $em));
    }

    public function testExtractCollectionChangesForOwnerUsesTrackableSnapshotWithoutDuckTyping(): void
    {
        $owner = new class {
            /** @var array<int, object> */
            public array $tags = [];
        };
        $removedTag = new TestCollectionItem(1);
        $owner->tags = [];

        $resolver = $this->createResolver();
        $collection = new StubCollection(
            $owner,
            [],
            [$removedTag],
            ManyToManyInverseSideMapping::fromMappingArray([
                'fieldName' => 'tags',
                'sourceEntity' => $owner::class,
                'targetEntity' => TestCollectionItem::class,
                'mappedBy' => 'owners',
                'isOwningSide' => false,
            ]),
            [$removedTag],
        );

        $metadata = self::createMock(ClassMetadata::class);
        $metadata->method('getAssociationNames')->willReturn(['tags']);
        $metadata->method('isCollectionValuedAssociation')->with('tags')->willReturn(true);
        $metadata->method('getFieldValue')->with($owner, 'tags')->willReturn($collection);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$owner]);
        $uow->method('getOriginalEntityData')->willReturn([]);

        self::assertSame([
            ['tags' => ['1']],
            ['tags' => []],
        ], $resolver->extractCollectionChangesForOwner($owner, $em, $uow));
    }

    public function testExtractCollectionChangesIndexedByOwnerCachesMetadataPerClassDuringFlushPass(): void
    {
        $firstOwner = new TestCollectionOwner();
        $secondOwner = new TestCollectionOwner();
        $firstOwner->tags = [];
        $secondOwner->tags = [];

        $resolver = $this->createResolver();

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getAssociationNames')->willReturn([]);

        $em = self::createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getClassMetadata')
            ->with($firstOwner::class)
            ->willReturn($metadata);

        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$firstOwner, $secondOwner]);
        $uow->method('getOriginalEntityData')->willReturn([]);

        self::assertSame([], $resolver->extractCollectionChangesIndexedByOwner($em, $uow));
    }

    private function createResolver(): CollectionChangeResolver
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturnCallback(static function (object $entity): string {
            if ($entity instanceof TestCollectionItem) {
                return (string) $entity->id;
            }

            throw new InvalidArgumentException('Unexpected entity type '.$entity::class);
        });

        return new CollectionChangeResolver(new CollectionIdExtractor($idResolver), new JoinTableCollectionIdLoader($idResolver));
    }
}

final class TestCollectionItem
{
    public function __construct(
        public int $id,
    ) {
    }
}

final class TestCollectionOwner
{
    /** @var array<int, object> */
    public array $tags = [];
}
