<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
            ['fieldName' => 'tags'],
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
            ['fieldName' => 'tags'],
            [$existingTag, $removedTag],
        );

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getAssociationNames')->willReturn([]);

        $em = self::createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('getClassMetadata')->with($owner::class)->willReturn($metadata);

        $uow = self::createMock(UnitOfWork::class);
        $uow->method('getScheduledCollectionUpdates')->willReturn([$collection]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->expects($this->once())->method('getOriginalEntityData')->with($owner)->willReturn([]);

        self::assertSame([
            ['tags' => ['1', '2']],
            ['tags' => ['1', '3']],
        ], $resolver->extractCollectionChangesForOwner($owner, $em, $uow));
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
