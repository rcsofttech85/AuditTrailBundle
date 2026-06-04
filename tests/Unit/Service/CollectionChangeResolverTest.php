<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeIndexBuilder;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;
use stdClass;

use function array_values;
use function get_object_vars;
use function is_int;
use function is_string;

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

    public function testBuildCollectionTransitionRecoversOldIdsWhenClearAndReplaceEmptiesSnapshot(): void
    {
        $owner = new class {
            public int $id = 10;
        };
        $removedTag = new TestCollectionItem(1);
        $addedTag = new TestCollectionItem(2);

        $resolver = $this->createResolver();
        $em = $this->createEntityManagerForDatabaseFallback($owner, [1]);

        $collection = new StubCollection(
            $owner,
            [$addedTag],
            [$removedTag],
            $this->createOwningTagsMapping($owner),
            [],
        );

        self::assertSame([
            'field' => 'tags',
            'old' => [1],
            'new' => ['2'],
        ], $resolver->buildCollectionTransition($collection, $em));
    }

    public function testBuildCollectionTransitionPreservesReAddedIdsWhenClearAndReAddShareSameEntity(): void
    {
        $owner = new class {
            public int $id = 10;
        };
        $tag = new TestCollectionItem(1);

        $resolver = $this->createResolver();
        $em = $this->createEntityManagerForDatabaseFallback($owner, [1]);

        $collection = new StubCollection(
            $owner,
            [$tag],
            [$tag],
            $this->createOwningTagsMapping($owner),
            [],
        );

        self::assertSame([
            'field' => 'tags',
            'old' => [1],
            'new' => ['1'],
        ], $resolver->buildCollectionTransition($collection, $em));
    }

    public function testBuildCollectionTransitionRecoversOldIdsForUnresolvedPendingInsertDiff(): void
    {
        $owner = new class {
            public int $id = 10;
        };
        $pendingItem = new stdClass();
        $mapping = ManyToManyOwningSideMapping::fromMappingArrayAndNamingStrategy([
            'fieldName' => 'items',
            'sourceEntity' => $owner::class,
            'targetEntity' => stdClass::class,
            'isOwningSide' => true,
            'joinTable' => [
                'name' => 'owner_item',
                'joinColumns' => [['name' => 'owner_id', 'referencedColumnName' => 'id']],
                'inverseJoinColumns' => [['name' => 'item_id', 'referencedColumnName' => 'id']],
            ],
        ], new DefaultNamingStrategy());

        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')
            ->willReturnCallback(static function (object $entity) use ($owner, $pendingItem): ?string {
                if ($entity === $pendingItem) {
                    return null;
                }

                if ($entity === $owner) {
                    return '10';
                }

                return null;
            });

        $collectionIdExtractor = new CollectionIdExtractor($idResolver);
        $joinTableLoader = new JoinTableCollectionIdLoader($idResolver);
        $resolver = new CollectionChangeResolver(
            $collectionIdExtractor,
            new CollectionChangeIndexBuilder($collectionIdExtractor, $joinTableLoader),
            $joinTableLoader,
        );

        $ownerMetadata = self::createMock(ClassMetadata::class);
        $ownerMetadata->expects($this->once())->method('getAssociationMapping')->with('items')->willReturn($mapping);
        $ownerMetadata->method('getFieldForColumn')->willReturn('id');
        $ownerMetadata->method('getTypeOfField')->willReturn('integer');

        $targetMetadata = self::createStub(ClassMetadata::class);
        $targetMetadata->method('getFieldForColumn')->willReturn('id');
        $targetMetadata->method('getTypeOfField')->willReturn('integer');

        $result = self::createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn([1]);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connection = self::createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $class) use ($owner, $ownerMetadata, $targetMetadata): ClassMetadata {
            if ($class === $owner::class) {
                return $ownerMetadata;
            }

            if ($class === stdClass::class) {
                return $targetMetadata;
            }

            throw new InvalidArgumentException('Unexpected metadata lookup for '.$class);
        });

        $collection = new StubCollection(
            $owner,
            [$pendingItem],
            [],
            $mapping,
            [],
        );

        self::assertSame([
            'field' => 'items',
            'old' => [1],
            'new' => [],
        ], $resolver->buildCollectionTransition($collection, $em));
    }

    public function testBuildCollectionTransitionDoesNotInitializeUninitializedPersistentCollection(): void
    {
        $owner = new class {
            public int $id = 10;
        };
        $addedTag = new TestCollectionItem(6);

        $resolver = $this->createResolver();
        $em = $this->createEntityManagerForDatabaseFallback($owner, [1, 2, 3, 4, 5]);

        $targetMetadata = self::createStub(ClassMetadata::class);
        $collection = new PersistentCollection($em, $targetMetadata, $this->createObjectCollection($addedTag));
        $collection->setOwner($owner, $this->createOwningTagsMapping($owner));
        $collection->setInitialized(false);
        $collection->setDirty(true);

        self::assertFalse($collection->isInitialized());

        self::assertSame([
            'field' => 'tags',
            'old' => [1, 2, 3, 4, 5],
            'new' => [1, 2, 3, 4, 5, '6'],
        ], $resolver->buildCollectionTransition($collection, $em));

        self::assertFalse($collection->isInitialized());
    }

    public function testBuildCollectionTransitionDoesNotDuplicateDatabaseFallbackIdsForUninitializedPersistentCollection(): void
    {
        $owner = new class {
            public int $id = 10;
        };
        $existingTag = new TestCollectionItem(2);

        $resolver = $this->createResolver();
        $em = $this->createEntityManagerForDatabaseFallback($owner, [1, 2, 3]);

        $targetMetadata = self::createStub(ClassMetadata::class);
        $collection = new PersistentCollection($em, $targetMetadata, $this->createObjectCollection($existingTag));
        $collection->setOwner($owner, $this->createOwningTagsMapping($owner));
        $collection->setInitialized(false);
        $collection->setDirty(true);

        self::assertSame([
            'field' => 'tags',
            'old' => [1, 2, 3],
            'new' => [1, 2, 3],
        ], $resolver->buildCollectionTransition($collection, $em));

        self::assertFalse($collection->isInitialized());
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
        $metadata->expects($this->once())
            ->method('isCollectionValuedAssociation')
            ->with('tags')
            ->willReturn(true);
        $metadata->expects($this->once())
            ->method('getFieldValue')
            ->with($owner, 'tags')
            ->willReturn($collection);

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

    private function createResolver(?JoinTableCollectionIdLoader $joinTableLoader = null): CollectionChangeResolver
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturnCallback(static function (object $entity): string {
            if ($entity instanceof TestCollectionItem) {
                return (string) $entity->id;
            }

            $id = get_object_vars($entity)['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                return (string) $id;
            }

            throw new InvalidArgumentException('Unexpected entity type '.$entity::class);
        });

        $collectionIdExtractor = new CollectionIdExtractor($idResolver);
        $joinTableLoader ??= new JoinTableCollectionIdLoader($idResolver);

        return new CollectionChangeResolver(
            $collectionIdExtractor,
            new CollectionChangeIndexBuilder($collectionIdExtractor, $joinTableLoader),
            $joinTableLoader,
        );
    }

    /**
     * @return ArrayCollection<int, object>
     */
    private function createObjectCollection(object ...$items): ArrayCollection
    {
        return new ArrayCollection(array_values($items));
    }

    private function createOwningTagsMapping(object $owner): ManyToManyOwningSideMapping
    {
        return ManyToManyOwningSideMapping::fromMappingArrayAndNamingStrategy([
            'fieldName' => 'tags',
            'sourceEntity' => $owner::class,
            'targetEntity' => TestCollectionItem::class,
            'isOwningSide' => true,
            'joinTable' => [
                'name' => 'owner_tag',
                'joinColumns' => [['name' => 'owner_id', 'referencedColumnName' => 'id']],
                'inverseJoinColumns' => [['name' => 'tag_id', 'referencedColumnName' => 'id']],
            ],
        ], new DefaultNamingStrategy());
    }

    /**
     * @param array<int, int|string> $databaseIds
     */
    private function createEntityManagerForDatabaseFallback(object $owner, array $databaseIds): EntityManagerInterface
    {
        $mapping = $this->createOwningTagsMapping($owner);

        $ownerMetadata = self::createStub(ClassMetadata::class);
        $ownerMetadata->method('getAssociationMapping')->willReturn($mapping);
        $ownerMetadata->method('getFieldForColumn')->willReturn('id');
        $ownerMetadata->method('getTypeOfField')->willReturn('integer');

        $targetMetadata = self::createStub(ClassMetadata::class);
        $targetMetadata->method('getFieldForColumn')->willReturn('id');
        $targetMetadata->method('getTypeOfField')->willReturn('integer');

        $result = self::createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn($databaseIds);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connection = self::createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $class) use ($owner, $ownerMetadata, $targetMetadata): ClassMetadata {
            if ($class === $owner::class) {
                return $ownerMetadata;
            }

            if ($class === TestCollectionItem::class) {
                return $targetMetadata;
            }

            throw new InvalidArgumentException('Unexpected metadata lookup for '.$class);
        });

        return $em;
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
