<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;

#[AllowMockObjectsWithoutExpectations]
class AuditSubscriberTest extends TestCase
{
    private AuditService&MockObject $auditService;
    private ChangeProcessor&MockObject $changeProcessor;
    private AuditDispatcher&MockObject $dispatcher;
    private ScheduledAuditManager&MockObject $auditManager;
    private EntityProcessor&MockObject $entityProcessor;
    private TransactionIdGenerator&MockObject $transactionIdGenerator;
    private LoggerInterface&MockObject $logger;
    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditService::class);
        $this->changeProcessor = $this->createMock(ChangeProcessor::class);
        $this->dispatcher = $this->createMock(AuditDispatcher::class);
        $this->auditManager = $this->createMock(ScheduledAuditManager::class);
        $this->entityProcessor = $this->createMock(EntityProcessor::class);
        $this->transactionIdGenerator = $this->createMock(TransactionIdGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->entityProcessor,
            $this->transactionIdGenerator,
            true,
            $this->logger,
            true
        );
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->subscriber->isEnabled());
    }

    public function testOnFlushDisabled(): void
    {
        $subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->entityProcessor,
            $this->transactionIdGenerator,
            true,
            null,
            false // Disabled
        );

        $args = $this->createMock(OnFlushEventArgs::class);
        $args->expects($this->never())->method('getObjectManager');

        $subscriber->onFlush($args);
    }

    public function testOnFlushRecursion(): void
    {

        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = $this->createMock(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->entityProcessor->expects($this->once())->method('processInsertions');

        $this->subscriber->onFlush($args);
    }

    public function testOnFlushBatchFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = $this->createMock(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        // Simulate batch threshold reached
        $this->auditManager->method('countScheduled')->willReturn(501);
        $this->auditManager->method('getScheduledAudits')->willReturn([
            [
                'audit' => new AuditLog(),
                'entity' => new \stdClass(),
                'is_insert' => false,
            ],
        ]);

        $this->dispatcher->expects($this->once())->method('dispatch');
        $this->auditManager->expects($this->once())->method('clear');

        $this->subscriber->onFlush($args);
    }

    public function testPostFlushProcessPendingDeletions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new \stdClass();
        $audit = new AuditLog();

        $this->auditManager->method('getPendingDeletions')->willReturn([
            ['entity' => $entity, 'data' => ['id' => 1]],
        ]);
        $this->changeProcessor->method('determineDeletionAction')->willReturn('delete');
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $em->expects($this->once())->method('persist')->with($audit);
        $this->dispatcher->expects($this->once())->method('dispatch');

        // Expect flush because hasNewAudits will be true
        $em->expects($this->once())->method('flush');

        $this->subscriber->postFlush($args);
    }

    public function testPostFlushProcessScheduledAuditsWithPendingId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new \stdClass();
        $audit = new AuditLog();

        $this->auditManager->method('getScheduledAudits')->willReturn([
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ]);

        $this->auditService->method('getEntityId')->willReturn('123');

        $this->dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $em->expects($this->once())->method('flush');

        $this->subscriber->postFlush($args);

        self::assertEquals('123', $audit->getEntityId());
    }

    public function testPostFlushFlushException(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $this->auditManager->method('getScheduledAudits')->willReturn([
            [
                'entity' => new \stdClass(),
                'audit' => new AuditLog(),
                'is_insert' => false,
            ],
        ]);
        $this->dispatcher->method('dispatch')->willReturn(true);

        $em->method('flush')->willThrowException(new \Exception('Flush failed'));

        $this->logger->expects($this->once())->method('critical');

        $this->subscriber->postFlush($args);
    }

    public function testOnClear(): void
    {
        $this->auditManager->expects($this->once())->method('clear');
        $this->subscriber->onClear();
    }
}
