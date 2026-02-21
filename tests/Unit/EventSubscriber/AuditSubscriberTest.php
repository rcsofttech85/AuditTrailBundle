<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class AuditSubscriberTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private ChangeProcessorInterface&MockObject $changeProcessor;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private MockScheduledAuditManager $auditManager;

    private EntityProcessorInterface&MockObject $entityProcessor;

    private TransactionIdGenerator&MockObject $transactionIdGenerator;

    private LoggerInterface&MockObject $logger;

    private AuditAccessHandler&MockObject $accessHandler;

    private EntityIdResolverInterface&MockObject $idResolver;

    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->changeProcessor = $this->createMock(ChangeProcessorInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $this->auditManager = new MockScheduledAuditManager();
        $this->entityProcessor = $this->createMock(EntityProcessorInterface::class);
        $this->transactionIdGenerator = $this->createMock(TransactionIdGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->accessHandler = $this->createMock(AuditAccessHandler::class);
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);

        $this->subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->entityProcessor,
            $this->transactionIdGenerator,
            $this->accessHandler,
            $this->idResolver,
            $this->logger
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
            $this->accessHandler,
            $this->idResolver,
            null,
            true,
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

    public function testOnFlushNoBatchFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = $this->createMock(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        // Simulate batch threshold reached - manually access property since implementation details allow it
        // But wait, the subscriber doesn't check count anymore in onFlush?
        // Let's check AuditSubscriber::onFlush again.
        // It calls handleBatchFlushIfNeeded.
        // handleBatchFlushIfNeeded was empty in the view!
        // "Removed nested flush in onFlush..."
        // So testOnFlushNoBatchFlush might be obsolete or testing nothing.
        // Let's check the code I viewed earlier.
        // handleBatchFlushIfNeeded was empty.
        // So this test is testing... empty method.
        // I'll keep it but remove the "countScheduled" expectation.

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->subscriber->onFlush($args);
    }

    public function testPostFlushProcessPendingDeletions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_DELETE);

        // property access on mock
        $this->auditManager->scheduledAudits = [];
        $this->auditManager->pendingDeletions = [
            ['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true],
        ];

        $this->changeProcessor->method('determineDeletionAction')->willReturn('delete');
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->dispatcher->expects($this->once())->method('dispatch');
        $em->method('contains')->with($audit)->willReturn(true);

        // Expect flush because hasNewAudits will be true
        $em->expects($this->once())->method('flush');

        $this->subscriber->postFlush($args);
    }

    public function testPostFlushProcessScheduledAuditsWithPendingId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $this->auditManager->pendingDeletions = [];
        $this->auditManager->scheduledAudits = [
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ];

        $this->idResolver->method('resolveFromEntity')->willReturn('123');

        $this->dispatcher->expects($this->once())->method('dispatch');
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');

        $this->subscriber->postFlush($args);

        self::assertEquals('123', $audit->entityId);
    }

    public function testPostFlushFlushException(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);

        $this->auditManager->pendingDeletions = [];
        $this->auditManager->scheduledAudits = [
            [
                'entity' => new stdClass(),
                'audit' => $audit,
                'is_insert' => false,
            ],
        ];

        $this->dispatcher->method('dispatch');
        $em->method('contains')->with($audit)->willReturn(true);

        $em->method('flush')->willThrowException(new Exception('Flush failed'));

        $this->logger->expects($this->once())->method('critical');

        $this->subscriber->postFlush($args);
    }

    public function testOnClear(): void
    {
        // Populate manager
        $this->auditManager->scheduledAudits = [
            ['entity' => new stdClass(), 'audit' => new AuditLog(stdClass::class, '1', 'create'), 'is_insert' => true],
        ];
        $this->subscriber->onClear();
        self::assertEmpty($this->auditManager->scheduledAudits);
    }
}
