<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use ArrayIterator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\UnitOfWork;
use IteratorAggregate;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;
use stdClass;
use Traversable;

#[AllowMockObjectsWithoutExpectations]
final class EntityProcessorTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private ChangeProcessorInterface&Stub $changeProcessor;

    private AuditDispatcherInterface&Stub $dispatcher;

    private ScheduledAuditManagerInterface&MockObject $auditManager;

    private EntityIdResolverInterface&Stub $idResolver;

    private EntityProcessor $processor;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->changeProcessor = self::createStub(ChangeProcessorInterface::class);
        $this->dispatcher = self::createStub(AuditDispatcherInterface::class);
        $this->auditManager = $this->createMock(ScheduledAuditManagerInterface::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);

        $this->processor = $this->createProcessor();
    }

    private function createProcessor(
        ?ChangeProcessorInterface $changeProcessor = null,
        ?AuditDispatcherInterface $dispatcher = null,
        bool $deferTransportUntilCommit = true,
        bool $failOnTransportError = false,
    ): EntityProcessor {
        $idResolver = $this->idResolver;

        return new EntityProcessor(
            $this->auditService,
            $changeProcessor ?? $this->changeProcessor,
            $dispatcher ?? $this->dispatcher,
            $this->auditManager,
            new AssociationImpactAnalyzer(new CollectionIdExtractor($idResolver), new CollectionTransitionMerger()),
            new CollectionChangeResolver(new CollectionIdExtractor($idResolver), new JoinTableCollectionIdLoader($idResolver)),
            new CollectionTransitionMerger(),
            $deferTransportUntilCommit,
            $failOnTransportError,
        );
    }

    public function testProcessInsertionsWithResolvedId(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        // Entity ID is already resolved (UUID case) — should dispatch immediately
        $audit = new AuditLog(stdClass::class, '550e8400-e29b-41d4-a716-446655440000', AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        // UUID path: dispatch during onFlush (single flush), NOT scheduled
        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $this->auditManager->expects($this->never())->method('schedule');

        $processor->processInsertions($em, $uow);
    }

    public function testProcessInsertionsDeferredForPendingId(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        // Entity ID is PENDING (auto-increment) — must defer to postFlush
        $audit = new AuditLog(stdClass::class, AuditLogInterface::PENDING_ID, AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        // Auto-increment path: scheduled for postFlush, NOT dispatched
        $dispatcher->expects($this->never())->method('dispatch');
        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, true);

        $processor->processInsertions($em, $uow);
    }

    public function testProcessInsertionsIgnored(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(false);

        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessUpdates(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['field' => ['old', 'new']]);

        $this->auditService->method('shouldAudit')->with($entity, AuditLogInterface::ACTION_UPDATE, ['field' => 'new'])->willReturn(true);
        $this->changeProcessor->method('extractChanges')->willReturn([['field' => 'old'], ['field' => 'new']]);
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditLogInterface::ACTION_UPDATE);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditLogInterface::ACTION_UPDATE, ['field' => 'old'], ['field' => 'new'])
            ->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, false);

        $this->processor->processUpdates($em, $uow);
    }

    public function testProcessUpdatesNoChanges(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->changeProcessor->method('extractChanges')->willReturn([[], []]);

        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processUpdates($em, $uow);
    }

    public function testProcessUpdatesMergesCollectionChangesForSameOwner(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $item1 = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        $item2 = new class {
            public function getId(): int
            {
                return 2;
            }
        };
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['title' => ['old', 'new']]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([
            new StubCollection($entity, [$item2], [], ['fieldName' => 'tags'], [$item1]),
        ]);

        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item1, $em, '1'],
            [$item2, $em, '2'],
        ]);
        $this->changeProcessor->method('extractChanges')->willReturn([['title' => 'old'], ['title' => 'new']]);
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditLogInterface::ACTION_UPDATE);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditLogInterface::ACTION_UPDATE, ['title' => 'new', 'tags' => ['1', '2']])
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $entity,
                AuditLogInterface::ACTION_UPDATE,
                ['title' => 'old', 'tags' => ['1']],
                ['title' => 'new', 'tags' => ['1', '2']]
            )
            ->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, false);

        $this->processor->processUpdates($em, $uow);
    }

    public function testProcessDeletions(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityDeletions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn(['data']);
        $em->method('contains')->willReturn(true);

        $this->auditManager->expects($this->once())->method('addPendingDeletion')->with($entity, ['data'], true);

        $this->processor->processDeletions($em, $uow);
    }

    public function testProcessCollectionUpdates(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);

        $owner = new stdClass();
        $item1 = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        $item2 = new class {
            public function getId(): int
            {
                return 2;
            }
        };
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $collection = new StubCollection(
            $owner,
            [$item2],
            [],
            ['fieldName' => 'items'],
            [$item1]
        );

        $this->auditService->method('shouldAudit')->with($owner)->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($owner, $audit, false);

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesSkipsOwnersBeingInserted(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);

        $owner = new stdClass();
        $item = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        $uow->method('getScheduledEntityInsertions')->willReturn([$owner]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);

        $collection = new StubCollection(
            $owner,
            [$item],
            [],
            ['fieldName' => 'items'],
            []
        );

        $this->auditService->expects($this->never())->method('shouldAudit');
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesReindexesIdsAfterDeletion(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);

        $owner = new stdClass();
        $item1 = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        $item2 = new class {
            public function getId(): int
            {
                return 2;
            }
        };
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $collection = new StubCollection(
            $owner,
            [],
            [$item1],
            ['fieldName' => 'items'],
            [$item1, $item2]
        );

        $this->auditService->method('shouldAudit')->with($owner)->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item1, $em, '1'],
            [$item2, $em, '2'],
        ]);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $owner,
                AuditLogInterface::ACTION_UPDATE,
                ['items' => ['1', '2']],
                ['items' => ['2']]
            )
            ->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($owner, $audit, false);

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesSkipsOwnersBeingUpdated(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);

        $owner = new stdClass();
        $item = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$owner]);

        $collection = new StubCollection(
            $owner,
            [$item],
            [],
            ['fieldName' => 'items'],
            []
        );

        $this->auditService->expects($this->never())->method('shouldAudit');
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesTracksCollectionClearWhenDiffsAreEmpty(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);

        $owner = new stdClass();
        $item = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $collection = new StubCollection(
            $owner,
            [],
            [],
            ['fieldName' => 'items'],
            [$item]
        );

        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item, $em, '1'],
        ]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditLogInterface::ACTION_UPDATE, ['items' => []])
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $owner,
                AuditLogInterface::ACTION_UPDATE,
                ['items' => ['1']],
                ['items' => []]
            )
            ->willReturn($audit);
        $this->auditManager->expects($this->once())->method('schedule')->with($owner, $audit, false);

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testDispatchImmediate(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher, deferTransportUntilCommit: false);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $this->auditManager->expects($this->never())->method('schedule');

        $processor->processInsertions($em, $uow);
    }

    public function testImmediateModeSkipsDispatchForInsertionsWithPendingId(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher, deferTransportUntilCommit: false);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, AuditLogInterface::PENDING_ID, AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $dispatcher->expects($this->never())->method('dispatch');
        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, true);

        $processor->processInsertions($em, $uow);
    }

    public function testImmediateModeWithFailOnTransportErrorDispatchesPendingInsertions(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher, deferTransportUntilCommit: false, failOnTransportError: true);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, AuditLogInterface::PENDING_ID, AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($audit, $em, AuditPhase::OnFlush, $uow, $entity)
            ->willReturn(false);
        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, true);

        $processor->processInsertions($em, $uow);
    }

    public function testProcessDeletionsSkipsSoftDeleteAlreadyTrackedAsUpdate(): void
    {
        $changeProcessor = self::createMock(ChangeProcessorInterface::class);
        $processor = $this->createProcessor(changeProcessor: $changeProcessor);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityDeletions')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturn([
            'deletedAt' => [null, '2026-04-02T00:00:00+00:00'],
        ]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $changeProcessor->expects($this->once())
            ->method('determineUpdateAction')
            ->with(['deletedAt' => [null, '2026-04-02T00:00:00+00:00']])
            ->willReturn(AuditLogInterface::ACTION_SOFT_DELETE);
        $this->auditService->expects($this->never())->method('getEntityData');
        $this->auditManager->expects($this->never())->method('addPendingDeletion');

        $processor->processDeletions($em, $uow);
    }

    public function testProcessDeletionsSkipsSoftDeleteTrackedInScheduledUpdates(): void
    {
        $changeProcessor = self::createMock(ChangeProcessorInterface::class);
        $processor = $this->createProcessor(changeProcessor: $changeProcessor);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityDeletions')->willReturn([$entity]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturnCallback(
            static fn (object $currentEntity): array => $currentEntity === $entity
                ? ['deletedAt' => [null, '2026-04-02T00:00:00+00:00']]
                : []
        );

        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $changeProcessor->expects($this->once())
            ->method('determineUpdateAction')
            ->with(['deletedAt' => [null, '2026-04-02T00:00:00+00:00']])
            ->willReturn(AuditLogInterface::ACTION_SOFT_DELETE);
        $this->auditService->expects($this->never())->method('getEntityData');
        $this->auditManager->expects($this->never())->method('addPendingDeletion');

        $processor->processDeletions($em, $uow);
    }

    public function testProcessUpdatesLoadsOriginalCollectionIdsFromDatabaseWhenSnapshotIsEmpty(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $owner = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $currentCollection = new class implements IteratorAggregate {
            /**
             * @return list<object>
             */
            public function getSnapshot(): array
            {
                return [];
            }

            public function getIterator(): Traversable
            {
                return new ArrayIterator([]);
            }
        };

        $connection = $this->createMock(Connection::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $result = self::createStub(Result::class);
        $ownerMetadata = $this->createMock(ClassMetadata::class);
        $targetMetadata = $this->createMock(ClassMetadata::class);
        $mapping = ManyToManyOwningSideMapping::fromMappingArrayAndNamingStrategy([
            'fieldName' => 'tags',
            'sourceEntity' => $owner::class,
            'targetEntity' => stdClass::class,
            'isOwningSide' => true,
            'joinTable' => [
                'name' => 'owner_tags',
                'joinColumns' => [[
                    'name' => 'owner_id',
                    'referencedColumnName' => 'id',
                ]],
                'inverseJoinColumns' => [[
                    'name' => 'tag_id',
                    'referencedColumnName' => 'id',
                ]],
            ],
        ], new DefaultNamingStrategy());

        $uow->method('getScheduledEntityUpdates')->willReturn([$owner]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn([]);
        $uow->expects($this->once())->method('getOriginalEntityData')->with($owner)->willReturn([]);

        $this->changeProcessor->method('extractChanges')->willReturn([[], []]);
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditLogInterface::ACTION_UPDATE);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $em->method('getClassMetadata')->willReturnMap([
            [$owner::class, $ownerMetadata],
            [stdClass::class, $targetMetadata],
        ]);
        $em->method('getConnection')->willReturn($connection);

        $ownerMetadata->method('getAssociationNames')->willReturn(['tags']);
        $ownerMetadata->method('isCollectionValuedAssociation')->with('tags')->willReturn(true);
        $ownerMetadata->method('getFieldValue')->with($owner, 'tags')->willReturn($currentCollection);
        $ownerMetadata->method('getAssociationMapping')->with('tags')->willReturn($mapping);
        $ownerMetadata->method('getFieldForColumn')->with('id')->willReturn('id');
        $ownerMetadata->method('getTypeOfField')->with('id')->willReturn('integer');

        $targetMetadata->method('getFieldForColumn')->with('id')->willReturn('id');
        $targetMetadata->method('getTypeOfField')->with('id')->willReturn('integer');

        $connection->method('convertToDatabaseValue')->with('1', 'integer')->willReturn(1);
        $connection->method('convertToPHPValue')->willReturnMap([
            ['5', 'integer', 5],
            ['7', 'integer', 7],
        ]);
        $connection->expects($this->once())->method('createQueryBuilder')->willReturn($queryBuilder);

        $queryBuilder->method('select')->with('tag_id')->willReturnSelf();
        $queryBuilder->method('from')->with('owner_tags')->willReturnSelf();
        $queryBuilder->method('where')->with('owner_id = :ownerId')->willReturnSelf();
        $queryBuilder->expects($this->once())->method('setParameter')->with('ownerId', 1, 'integer')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $result->method('fetchFirstColumn')->willReturn(['5', '7']);

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditLogInterface::ACTION_UPDATE, ['tags' => []])
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($owner, AuditLogInterface::ACTION_UPDATE, ['tags' => [5, 7]], ['tags' => []])
            ->willReturn($audit);
        $this->auditManager->expects($this->once())->method('schedule')->with($owner, $audit, false);

        $this->processor->processUpdates($em, $uow);
    }

    public function testProcessDeletionsAggregatesRelatedCollectionImpacts(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $deletedA = new class {};
        $deletedB = new class {};
        $relatedEntity = new class {};
        $audit = new AuditLog($relatedEntity::class, '10', AuditLogInterface::ACTION_UPDATE);
        $deletedMetadata = $this->createMock(ClassMetadata::class);
        $relatedMetadata = $this->createMock(ClassMetadata::class);
        $mapping = OneToManyAssociationMapping::fromMappingArray([
            'fieldName' => 'related',
            'sourceEntity' => $deletedA::class,
            'targetEntity' => $relatedEntity::class,
            'mappedBy' => 'items',
            'isOwningSide' => false,
        ]);

        $uow->method('getScheduledEntityDeletions')->willReturn([$deletedA, $deletedB]);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);

        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$deletedA, $em, '1'],
            [$deletedB, $em, '2'],
            [$relatedEntity, $em, '10'],
        ]);

        $em->method('getClassMetadata')->willReturnMap([
            [$deletedA::class, $deletedMetadata],
            [$deletedB::class, $deletedMetadata],
            [$relatedEntity::class, $relatedMetadata],
        ]);
        $em->method('contains')->willReturn(true);

        $deletedMetadata->method('getAssociationNames')->willReturn(['related']);
        $deletedMetadata->method('getAssociationMapping')->with('related')->willReturn($mapping);
        $deletedMetadata->method('getFieldValue')->willReturnMap([
            [$deletedA, 'related', [$relatedEntity]],
            [$deletedB, 'related', [$relatedEntity]],
        ]);

        $relatedMetadata->method('getFieldValue')->with($relatedEntity, 'items')->willReturn([$deletedA, $deletedB]);

        $this->auditService->expects($this->exactly(3))
            ->method('shouldAudit')
            ->willReturnCallback(static function (object $entity, ?string $action = null, ?array $newValues = null) use ($deletedA, $deletedB, $relatedEntity): bool {
                if ($entity === $relatedEntity) {
                    return $action === AuditLogInterface::ACTION_UPDATE && $newValues === ['items' => []];
                }

                return $entity === $deletedA || $entity === $deletedB;
            });
        $this->auditService->expects($this->exactly(2))
            ->method('getEntityData')
            ->willReturn([]);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $relatedEntity,
                AuditLogInterface::ACTION_UPDATE,
                ['items' => ['1', '2']],
                ['items' => []]
            )
            ->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($relatedEntity, $audit, false);
        $this->auditManager->expects($this->exactly(2))
            ->method('addPendingDeletion')
            ->withAnyParameters();

        $this->processor->processDeletions($em, $uow);
    }
}
