<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;

#[AllowMockObjectsWithoutExpectations]
class EntityProcessorTest extends TestCase
{
    private AuditService&MockObject $auditService;
    private ChangeProcessor&MockObject $changeProcessor;
    private AuditDispatcher&MockObject $dispatcher;
    private ScheduledAuditManager&MockObject $auditManager;
    private EntityProcessor $processor;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditService::class);
        $this->changeProcessor = $this->createMock(ChangeProcessor::class);
        $this->dispatcher = $this->createMock(AuditDispatcher::class);
        $this->auditManager = $this->createMock(ScheduledAuditManager::class);

        $this->processor = new EntityProcessor(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            true // deferTransportUntilCommit
        );
    }

    public function testProcessInsertions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new \stdClass();
        $audit = new AuditLog();

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->auditManager->expects($this->once())->method('schedule')->with($entity, $audit, true);

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessInsertionsIgnored(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new \stdClass();

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(false);

        $this->auditManager->expects($this->never())->method('schedule');

        $this->processor->processInsertions($em, $uow);
    }

    public function testProcessUpdates(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new \stdClass();
        $audit = new AuditLog();

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
        $entity = new \stdClass();

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
        $entity = new \stdClass();

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

        $owner = new \stdClass();
        $item1 = new class () {
            public function getId(): int
            {
                return 1;
            }
        };
        $item2 = new class () {
            public function getId(): int
            {
                return 2;
            }
        };
        $audit = new AuditLog();

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

        // @phpstan-ignore-next-line
        $this->processor->processCollectionUpdates($em, $uow, [$collection]);
    }

    public function testDispatchImmediate(): void
    {
        $processor = new EntityProcessor(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            false // deferTransportUntilCommit = false
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $entity = new \stdClass();
        $audit = new AuditLog();

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $this->auditService->method('shouldAudit')->with($entity)->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->dispatcher->method('supports')->willReturn(true);
        $this->dispatcher->expects($this->once())->method('dispatch');
        $this->auditManager->expects($this->never())->method('schedule');

        $processor->processInsertions($em, $uow);
    }
}
