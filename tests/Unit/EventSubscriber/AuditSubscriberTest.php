<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\UnitOfWork;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
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
final class AuditSubscriberTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private ChangeProcessorInterface&Stub $changeProcessor;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private MockScheduledAuditManager $auditManager;

    private EntityProcessorInterface&MockObject $entityProcessor;

    private TransactionIdGenerator&Stub $transactionIdGenerator;

    private LoggerInterface&MockObject $logger;

    private AuditAccessHandler&MockObject $accessHandler;

    private EntityIdResolverInterface&Stub $idResolver;

    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = self::createMock(AuditServiceInterface::class);
        $this->changeProcessor = self::createStub(ChangeProcessorInterface::class);
        $this->dispatcher = self::createMock(AuditDispatcherInterface::class);
        $this->auditManager = new MockScheduledAuditManager();
        $this->entityProcessor = self::createMock(EntityProcessorInterface::class);
        $this->transactionIdGenerator = self::createStub(TransactionIdGenerator::class);
        $this->logger = self::createMock(LoggerInterface::class);
        $this->accessHandler = self::createMock(AuditAccessHandler::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);

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
        $subscriber = $this->createDisabledSubscriber();

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

        // First call should proceed
        $this->entityProcessor->expects($this->once())->method('processInsertions');

        // During processInsertions (representing the work in onFlush), we simulate another flush event
        $this->entityProcessor->method('processInsertions')->willReturnCallback(function () use ($args) {
            // Second call should return early
            $this->subscriber->onFlush($args);
        });

        $this->subscriber->onFlush($args);
    }

    public function testOnFlushDoesNotDispatchDirectly(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = $this->createMock(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

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

    public function testPostLoadDisabled(): void
    {
        $subscriber = $this->createDisabledSubscriber();

        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $this->accessHandler->expects($this->never())->method('handleAccess');

        $subscriber->postLoad($args);
    }

    public function testPostLoadEnabled(): void
    {
        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);

        $this->accessHandler->expects($this->once())->method('handleAccess')->with($entity, $em);

        $this->subscriber->postLoad($args);
    }

    public function testPostFlushDisabled(): void
    {
        $subscriber = $this->createDisabledSubscriber();

        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($this->createMock(EntityManagerInterface::class));

        $this->auditManager->pendingDeletions = [['entity' => new stdClass(), 'data' => [], 'is_managed' => true]];
        $this->auditService->expects($this->never())->method('createAuditLog');

        $subscriber->postFlush($args);
    }

    public function testPostFlushProcessPendingDeletionsSkipped(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $this->auditManager->pendingDeletions = [['entity' => new stdClass(), 'data' => [], 'is_managed' => true]];
        // action is null
        $this->changeProcessor->method('determineDeletionAction')->willReturn(null);

        $this->auditService->expects($this->never())->method('createAuditLog');

        $this->subscriber->postFlush($args);
    }

    public function testPostFlushSoftDelete(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $this->auditManager->pendingDeletions = [['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true]];

        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditLogInterface::ACTION_SOFT_DELETE);
        $this->auditService->expects($this->once())->method('getEntityData')->with($entity)->willReturn(['name' => 'soft']);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);
        $this->auditService->method('createAuditLog')->willReturn($audit);

        $this->subscriber->postFlush($args);
    }

    public function testPostFlushProcessScheduledAuditsWithStaticId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '123', AuditLogInterface::ACTION_CREATE);

        $this->auditManager->scheduledAudits = [
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ];

        // resolveFromEntity returns something other than PENDING_ID
        $this->idResolver->method('resolveFromEntity')->willReturn('456');

        $this->subscriber->postFlush($args);

        self::assertEquals('456', $audit->entityId);
    }

    public function testReset(): void
    {
        $this->accessHandler->expects($this->once())->method('reset');
        $this->subscriber->reset();
    }

    public function testRecursionPreventionViaIsFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [
            ['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false],
        ];

        $em->method('contains')->willReturn(true);

        // When em->flush() is called, we call postFlush again to see if it recurses
        $em->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use ($args) {
                // If we are here, isFlushing must be true
                // Calling postFlush again should return early immediately due to isFlushing
                $this->subscriber->postFlush($args);
            });

        $this->subscriber->postFlush($args);
    }

    public function testPostFlushWithLoggerButNoException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->entityProcessor,
            $this->transactionIdGenerator,
            $this->accessHandler,
            $this->idResolver,
            $logger
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');
        $logger->expects($this->never())->method('critical');

        $subscriber->postFlush($args);
    }

    public function testPostFlushFlushExceptionWithoutLogger(): void
    {
        $subscriber = $this->createSubscriberWithLogger(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $em->method('contains')->with($audit)->willReturn(true);
        $em->method('flush')->willThrowException(new Exception('Flush failed'));

        // Verifies null-safe operator (?->) on logger — if mutated to (->), this crashes
        $subscriber->postFlush($args);
    }

    public function testPostFlushFlushExceptionLogsCorrectContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriberWithLogger($logger);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = $this->createMock(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $em->method('contains')->with($audit)->willReturn(true);
        $em->method('flush')->willThrowException(new Exception('Critical error'));

        $logger->expects($this->once())
            ->method('critical')
            ->with('Failed to flush audits', self::callback(static function (array $context): bool {
                return isset($context['exception']) && $context['exception'] === 'Critical error';
            }));

        $subscriber->postFlush($args);
    }

    private function createDisabledSubscriber(): AuditSubscriber
    {
        return new AuditSubscriber(
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
            false,
        );
    }

    private function createSubscriberWithLogger(?LoggerInterface $logger): AuditSubscriber
    {
        return new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->entityProcessor,
            $this->transactionIdGenerator,
            $this->accessHandler,
            $this->idResolver,
            $logger,
        );
    }
}
