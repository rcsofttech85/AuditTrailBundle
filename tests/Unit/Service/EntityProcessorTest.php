<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeIndexBuilder;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\DeferredCollectionDetector;
use Rcsofttech\AuditTrailBundle\Service\DeletedAssociationImpactResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityAuditDispatchManager;
use Rcsofttech\AuditTrailBundle\Service\EntityCollectionUpdateProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityDeletionProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityInsertionProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityUpdateProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityUpdateTransitionResolver;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;
use stdClass;

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
        $collectionIdExtractor = new CollectionIdExtractor($idResolver);
        $joinTableLoader = new JoinTableCollectionIdLoader($idResolver);
        $deletedAssociationImpactResolver = new DeletedAssociationImpactResolver();
        $collectionTransitionMerger = new CollectionTransitionMerger();
        $collectionChangeResolver = new CollectionChangeResolver(
            $collectionIdExtractor,
            new CollectionChangeIndexBuilder($collectionIdExtractor, $joinTableLoader),
            $joinTableLoader,
        );

        return new EntityProcessor(
            new EntityInsertionProcessor(
                $this->auditService,
                $this->auditManager,
                new DeferredCollectionDetector($collectionChangeResolver),
                new EntityAuditDispatchManager(
                    $dispatcher ?? $this->dispatcher,
                    $this->auditManager,
                    $deferTransportUntilCommit,
                    $failOnTransportError,
                ),
            ),
            new EntityUpdateProcessor(
                $this->auditService,
                $this->auditManager,
                new AssociationImpactAnalyzer(new CollectionIdExtractor($idResolver), new CollectionTransitionMerger()),
                new DeferredCollectionDetector($collectionChangeResolver),
                new EntityUpdateTransitionResolver(
                    $changeProcessor ?? $this->changeProcessor,
                    $deletedAssociationImpactResolver,
                    $collectionChangeResolver,
                    $collectionTransitionMerger,
                ),
                new EntityAuditDispatchManager(
                    $dispatcher ?? $this->dispatcher,
                    $this->auditManager,
                    $deferTransportUntilCommit,
                    $failOnTransportError,
                ),
            ),
            new EntityCollectionUpdateProcessor(
                $this->auditService,
                $this->auditManager,
                $collectionChangeResolver,
                new DeferredCollectionDetector($collectionChangeResolver),
                $collectionTransitionMerger,
                new EntityAuditDispatchManager(
                    $dispatcher ?? $this->dispatcher,
                    $this->auditManager,
                    $deferTransportUntilCommit,
                    $failOnTransportError,
                ),
            ),
            new EntityDeletionProcessor(
                $this->auditService,
                $changeProcessor ?? $this->changeProcessor,
                $this->auditManager,
                new AssociationImpactAnalyzer(new CollectionIdExtractor($idResolver), new CollectionTransitionMerger()),
                new EntityAuditDispatchManager(
                    $dispatcher ?? $this->dispatcher,
                    $this->auditManager,
                    $deferTransportUntilCommit,
                    $failOnTransportError,
                ),
                true,
            ),
        );
    }

    /**
     * @param class-string $sourceEntity
     */
    private function createStubCollectionMapping(string $fieldName, string $sourceEntity): ManyToManyInverseSideMapping
    {
        return ManyToManyInverseSideMapping::fromMappingArray([
            'fieldName' => $fieldName,
            'sourceEntity' => $sourceEntity,
            'targetEntity' => stdClass::class,
            'mappedBy' => 'owners',
            'isOwningSide' => false,
        ]);
    }

    public function testProcessInsertionsWithResolvedIdDefersWhenTransportDeferred(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        // Entity ID is already resolved (UUID case) but deferred transport waits until postFlush.
        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Create, [])
            ->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $audit = new AuditLog(stdClass::class, '550e8400-e29b-41d4-a716-446655440000', AuditAction::Create);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);

        $dispatcher->expects($this->never())->method('dispatch');
        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, true);
        $this->auditManager->expects($this->never())->method('schedulePendingAuditPlan');

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
        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Create, [])
            ->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $audit = new AuditLog(stdClass::class, null, AuditAction::Create);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
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
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(false);

        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessUpdates(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['field' => ['old', 'new']]);

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Update, ['field' => 'new'])
            ->willReturn(true);
        $this->changeProcessor->method('extractChanges')->willReturn([['field' => 'old'], ['field' => 'new']]);
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditAction::Update);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
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

        $this->auditService->expects($this->never())->method('shouldAudit');
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
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['title' => ['old', 'new']]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([
            new StubCollection($entity, [$item2], [], $this->createStubCollectionMapping('tags', $entity::class), [$item1]),
        ]);

        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item1, $em, '1'],
            [$item2, $em, '2'],
        ]);
        $this->changeProcessor->method('extractChanges')->willReturn([['title' => 'old'], ['title' => 'new']]);
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditAction::Update);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Update, ['title' => 'new', 'tags' => ['1', '2']])
            ->willReturn(true);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditAction::Update, ['title' => 'old', 'tags' => ['1']], ['title' => 'new', 'tags' => ['1', '2']])
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
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn(['data']);
        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditAction::Delete);

        $this->auditManager->expects($this->once())->method('addPendingDeletion')->with($entity, ['data'], AuditAction::Delete);

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
        $collection = new StubCollection(
            $owner,
            [$item2],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
            [$item1]
        );

        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item1, $em, '1'],
            [$item2, $em, '2'],
        ]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditAction::Update, ['items' => ['1', '2']])
            ->willReturn(true);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
        $this->auditManager->expects($this->once())->method('schedule')->with($owner, $audit, false);

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesSkipsOwnersBeingInserted(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createMock(UnitOfWork::class);

        $owner = new stdClass();
        $item = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        $uow->expects($this->once())->method('isScheduledForInsert')->with($owner)->willReturn(true);

        $collection = new StubCollection(
            $owner,
            [$item],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
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
        $collection = new StubCollection(
            $owner,
            [],
            [$item1],
            $this->createStubCollectionMapping('items', $owner::class),
            [$item1, $item2]
        );

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner)
            ->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item1, $em, '1'],
            [$item2, $em, '2'],
        ]);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($owner, AuditAction::Update, ['items' => ['1', '2']], ['items' => ['2']])
            ->willReturn($audit);
        $this->auditManager->expects($this->once())->method('schedule')->with($owner, $audit, false);

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesSkipsOwnersBeingUpdated(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createMock(UnitOfWork::class);

        $owner = new stdClass();
        $item = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        $uow->expects($this->once())->method('isScheduledForInsert')->with($owner)->willReturn(false);
        $uow->expects($this->once())->method('isScheduledForUpdate')->with($owner)->willReturn(true);

        $collection = new StubCollection(
            $owner,
            [$item],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
            []
        );

        $this->auditService->expects($this->never())->method('shouldAudit');
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testProcessCollectionUpdatesUsesDirectUnitOfWorkMembershipChecks(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createMock(UnitOfWork::class);

        $owner = new stdClass();
        $item = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        $collection = new StubCollection(
            $owner,
            [$item],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
            []
        );

        $uow->expects($this->once())
            ->method('isScheduledForInsert')
            ->with($owner)
            ->willReturn(true);
        $uow->expects($this->never())->method('getScheduledEntityInsertions');
        $uow->expects($this->never())->method('getScheduledEntityUpdates');

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
        $collection = new StubCollection(
            $owner,
            [],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
            [$item]
        );

        $this->idResolver->method('resolveFromEntity')->willReturnMap([
            [$item, $em, '1'],
        ]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditAction::Update, ['items' => []])
            ->willReturn(true);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($owner, AuditAction::Update, ['items' => ['1']], ['items' => []])
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
        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $this->auditManager->expects($this->never())->method('schedulePendingAuditPlan');

        $processor->processInsertions($em, $uow);
    }

    public function testImmediateModeSkipsDispatchForInsertionsWithPendingId(): void
    {
        $dispatcher = self::createMock(AuditDispatcherInterface::class);
        $processor = $this->createProcessor(dispatcher: $dispatcher, deferTransportUntilCommit: false);
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $audit = new AuditLog(stdClass::class, null, AuditAction::Create);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
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
        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $audit = new AuditLog(stdClass::class, null, AuditAction::Create);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
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
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $changeProcessor->expects($this->once())
            ->method('determineUpdateAction')
            ->with(['deletedAt' => [null, '2026-04-02T00:00:00+00:00']])
            ->willReturn(AuditAction::SoftDelete);
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

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $changeProcessor->expects($this->once())
            ->method('determineUpdateAction')
            ->with(['deletedAt' => [null, '2026-04-02T00:00:00+00:00']])
            ->willReturn(AuditAction::SoftDelete);
        $this->auditService->expects($this->never())->method('getEntityData');
        $this->auditManager->expects($this->never())->method('addPendingDeletion');

        $processor->processDeletions($em, $uow);
    }

    public function testProcessUpdatesLoadsOriginalCollectionIdsFromDatabaseWhenSnapshotIsEmpty(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $owner = new stdClass();
        $currentCollection = new StubCollection(
            $owner,
            [],
            [],
            $this->createStubCollectionMapping('tags', $owner::class),
            [],
        );

        $connection = $this->createMock(Connection::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $result = self::createStub(Result::class);
        $ownerMetadata = $this->createMock(ClassMetadata::class);
        $targetMetadata = self::createStub(ClassMetadata::class);
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
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditAction::Update);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $em->method('getClassMetadata')->willReturnMap([
            [$owner::class, $ownerMetadata],
            [stdClass::class, $targetMetadata],
        ]);
        $em->method('getConnection')->willReturn($connection);

        $ownerMetadata->method('getAssociationNames')->willReturn(['tags']);
        $ownerMetadata->expects($this->once())
            ->method('isCollectionValuedAssociation')
            ->with('tags')
            ->willReturn(true);
        $ownerMetadata->expects($this->exactly(2))
            ->method('getFieldValue')
            ->with($owner, 'tags')
            ->willReturn($currentCollection);
        $ownerMetadata->expects($this->once())
            ->method('getAssociationMapping')
            ->with('tags')
            ->willReturn($mapping);
        $ownerMetadata->method('getFieldForColumn')->willReturn('id');
        $ownerMetadata->expects($this->exactly(2))
            ->method('getTypeOfField')
            ->with('id')
            ->willReturn('integer');

        $targetMetadata->method('getFieldForColumn')->willReturn('id');
        $targetMetadata->method('getTypeOfField')->willReturn('integer');

        $connection->method('convertToPHPValue')->willReturnMap([
            ['5', 'integer', 5],
            ['7', 'integer', 7],
        ]);
        $connection->expects($this->once())->method('createQueryBuilder')->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->with('tag_id')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('from')
            ->with('owner_tags')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('owner_id = :ownerId')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())->method('setParameter')->with('ownerId', 1, 'integer')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $result->method('fetchFirstColumn')->willReturn(['5', '7']);

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditAction::Update, ['tags' => []])
            ->willReturn(true);
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($owner, AuditAction::Update, ['tags' => [5, 7]], ['tags' => []])
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
        $audit = new AuditLog($relatedEntity::class, '10', AuditAction::Update);
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
        $deletedMetadata->expects($this->exactly(2))
            ->method('isCollectionValuedAssociation')
            ->with('related')
            ->willReturn(true);
        $deletedMetadata->expects($this->exactly(2))
            ->method('getAssociationMapping')
            ->with('related')
            ->willReturn($mapping);
        $deletedMetadata->method('getFieldValue')->willReturnMap([
            [$deletedA, 'related', [$relatedEntity]],
            [$deletedB, 'related', [$relatedEntity]],
        ]);

        $relatedMetadata->expects($this->exactly(2))
            ->method('getFieldValue')
            ->with($relatedEntity, 'items')
            ->willReturn([$deletedA, $deletedB]);

        $this->auditService->expects($this->exactly(3))
            ->method('shouldAudit')
            ->willReturnCallback(static function (object $entity, AuditAction|string|null $action = null, ?array $newValues = null) use ($deletedA, $deletedB, $relatedEntity): bool {
                if ($entity === $relatedEntity) {
                    return $action === AuditAction::Update && $newValues === ['items' => []];
                }

                return $entity === $deletedA || $entity === $deletedB;
            });
        $this->auditService->expects($this->exactly(2))
            ->method('getEntityData')
            ->willReturn([]);
        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditAction::Delete);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $relatedEntity,
                AuditAction::Update,
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
