<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class EntityProcessorTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private ChangeProcessorInterface&MockObject $changeProcessor;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private ScheduledAuditManagerInterface&MockObject $auditManager;

    private EntityIdResolverInterface&MockObject $idResolver;

    private EntityProcessor $processor;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->changeProcessor = $this->createMock(ChangeProcessorInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $this->auditManager = $this->createMock(ScheduledAuditManagerInterface::class);
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);

        $this->processor = new EntityProcessor(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->idResolver,
            true // deferTransportUntilCommit
        );
    }

    public function testProcessInsertionsWithResolvedId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new stdClass();
        // Entity ID is already resolved (UUID case) — should dispatch immediately
        $audit = new AuditLog(stdClass::class, '550e8400-e29b-41d4-a716-446655440000', AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        // UUID path: dispatch during onFlush (single flush), NOT scheduled
        $this->dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessInsertionsDeferredForPendingId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new stdClass();
        // Entity ID is PENDING (auto-increment) — must defer to postFlush
        $audit = new AuditLog(stdClass::class, AuditLogInterface::PENDING_ID, AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        // Auto-increment path: scheduled for postFlush, NOT dispatched
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, true);

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessInsertionsIgnored(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(false);

        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessUpdates(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturn(['field' => ['old', 'new']]);

        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->changeProcessor->method('extractChanges')->willReturn([['field' => 'old'], ['field' => 'new']]);
        $this->changeProcessor->method('determineUpdateAction')->willReturn(AuditLogInterface::ACTION_UPDATE);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, false);

        $this->processor->processUpdates($em, $uow);
    }

    public function testProcessUpdatesNoChanges(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new stdClass();

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->changeProcessor->method('extractChanges')->willReturn([[], []]);

        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processUpdates($em, $uow);
    }

    public function testProcessDeletions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
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
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);

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

    public function testDispatchImmediate(): void
    {
        $processor = new EntityProcessor(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->idResolver,
            false // deferTransportUntilCommit = false
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $this->auditManager->expects($this->never())->method('schedule');

        $processor->processInsertions($em, $uow);
    }
}
