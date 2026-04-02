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
use Rcsofttech\AuditTrailBundle\Exception\AuditException;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use ReflectionClass;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
final class AuditSubscriberTest extends TestCase
{
    private AuditServiceInterface&Stub $auditService;

    private ChangeProcessorInterface&Stub $changeProcessor;

    private AuditDispatcherInterface&Stub $dispatcher;

    private MockScheduledAuditManager $auditManager;

    private EntityProcessorInterface&Stub $entityProcessor;

    private TransactionIdGenerator&Stub $transactionIdGenerator;

    private LoggerInterface&Stub $logger;

    private AuditAccessHandler&Stub $accessHandler;

    private EntityIdResolverInterface&Stub $idResolver;

    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = self::createStub(AuditServiceInterface::class);
        $this->changeProcessor = self::createStub(ChangeProcessorInterface::class);
        $this->dispatcher = self::createStub(AuditDispatcherInterface::class);
        $this->auditManager = new MockScheduledAuditManager();
        $this->entityProcessor = self::createStub(EntityProcessorInterface::class);
        $this->transactionIdGenerator = self::createStub(TransactionIdGenerator::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->accessHandler = self::createStub(AuditAccessHandler::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);

        $this->subscriber = $this->createSubscriber();
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->subscriber->isEnabled());
    }

    public function testOnFlushDisabled(): void
    {
        $this->auditManager->disable();

        $args = $this->createMock(OnFlushEventArgs::class);
        $args->expects($this->never())->method('getObjectManager');

        $this->subscriber->onFlush($args);
    }

    public function testOnFlushRecursion(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $args = self::createStub(OnFlushEventArgs::class);
        $entityProcessor = $this->createMock(EntityProcessorInterface::class);
        $subscriber = $this->createSubscriber(entityProcessor: $entityProcessor);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $entityProcessor->expects($this->once())->method('processInsertions');

        $entityProcessor->method('processInsertions')->willReturnCallback(static function () use ($args, $subscriber) {
            $subscriber->onFlush($args);
        });

        $subscriber->onFlush($args);
    }

    public function testOnFlushDoesNotDispatchDirectly(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $args = self::createStub(OnFlushEventArgs::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $dispatcher->expects($this->never())->method('dispatch');

        $subscriber->onFlush($args);
    }

    public function testPostFlushProcessPendingDeletions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = self::createStub(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $subscriber = $this->createSubscriber(auditService: $auditService, dispatcher: $dispatcher);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_DELETE);

        $this->auditManager->scheduledAudits = [];
        $this->auditManager->pendingDeletions = [
            ['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true],
        ];

        $this->changeProcessor->method('determineDeletionAction')->willReturn('delete');
        $auditService->method('createAuditLog')->willReturn($audit);

        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);

        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);
    }

    public function testPostFlushProcessScheduledAuditsWithPendingId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $this->auditManager->pendingDeletions = [];
        $this->auditManager->scheduledAudits = [
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ];

        $this->idResolver->method('resolveFromEntity')->willReturn('123');

        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);

        self::assertSame('123', $audit->entityId);
    }

    public function testPostFlushRetainsScheduledAuditWhenDispatchFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, accessHandler: $accessHandler);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->pendingDeletions = [];
        $this->auditManager->scheduledAudits = [
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => false],
        ];

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($audit, $em, 'post_flush', null, $entity)
            ->willReturn(false);
        $accessHandler->expects($this->never())->method('markAsAudited');
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);

        self::assertNotEmpty($this->auditManager->scheduledAudits);
        self::assertSame($audit, $this->auditManager->scheduledAudits[0]['audit']);
    }

    public function testPostFlushRetriesRetainedScheduledAuditOnNextFlushWithoutDuplication(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, accessHandler: $accessHandler);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->pendingDeletions = [];
        $this->auditManager->scheduledAudits = [
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => false],
        ];

        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->with($audit, $em, 'post_flush', null, $entity)
            ->willReturnOnConsecutiveCalls(false, true);
        $accessHandler->expects($this->once())->method('markAsAudited');
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);
        self::assertNotEmpty($this->auditManager->scheduledAudits);

        $subscriber->postFlush($args);
        self::assertEmpty($this->auditManager->scheduledAudits);
    }

    public function testPostFlushFlushException(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = self::createStub(AuditDispatcherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, logger: $logger);
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

        $dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);

        $em->method('flush')->willThrowException(new Exception('Flush failed'));
        $em->method('isOpen')->willReturn(true);

        $logger->expects($this->once())->method('critical');

        $subscriber->postFlush($args);
    }

    public function testOnClear(): void
    {
        $this->auditManager->scheduledAudits = [
            ['entity' => new stdClass(), 'audit' => new AuditLog(stdClass::class, '1', 'create'), 'is_insert' => true],
        ];
        $this->subscriber->onClear();
        self::assertEmpty($this->auditManager->scheduledAudits);
    }

    public function testPostLoadDisabled(): void
    {
        $this->auditManager->disable();

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);
        $accessHandler->expects($this->never())->method('handleAccess');

        $subscriber->postLoad($args);
    }

    public function testPostLoadEnabled(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);

        $accessHandler->expects($this->once())->method('handleAccess')->with($entity, $em);

        $subscriber->postLoad($args);
    }

    public function testPostLoadIsSkippedDuringOnFlushProcessing(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);

        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('onFlushProcessing');
        $property->setValue($subscriber, true);

        $accessHandler->expects($this->never())->method('handleAccess');

        $subscriber->postLoad($args);
    }

    public function testPostLoadIsSkippedDuringPostFlushProcessing(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);

        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('postFlushDepth');
        $property->setValue($subscriber, 1);

        $accessHandler->expects($this->never())->method('handleAccess');

        $subscriber->postLoad($args);
    }

    public function testPostFlushDisabled(): void
    {
        $this->auditManager->disable();

        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn(self::createStub(EntityManagerInterface::class));
        $auditService = $this->createMock(AuditServiceInterface::class);
        $subscriber = $this->createSubscriber(auditService: $auditService);

        $this->auditManager->pendingDeletions = [['entity' => new stdClass(), 'data' => [], 'is_managed' => true]];
        $auditService->expects($this->never())->method('createAuditLog');

        $subscriber->postFlush($args);
    }

    public function testPostFlushProcessPendingDeletionsSkipped(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = $this->createMock(AuditServiceInterface::class);
        $subscriber = $this->createSubscriber(auditService: $auditService);
        $args->method('getObjectManager')->willReturn($em);

        $this->auditManager->pendingDeletions = [['entity' => new stdClass(), 'data' => [], 'is_managed' => true]];
        $this->changeProcessor->method('determineDeletionAction')->willReturn(null);

        $auditService->expects($this->never())->method('createAuditLog');

        $subscriber->postFlush($args);
    }

    public function testPostFlushSoftDelete(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = $this->createMock(AuditServiceInterface::class);
        $dispatcher = self::createStub(AuditDispatcherInterface::class);
        $subscriber = $this->createSubscriber(auditService: $auditService, dispatcher: $dispatcher);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $this->auditManager->pendingDeletions = [['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true]];

        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditLogInterface::ACTION_SOFT_DELETE);
        $auditService->expects($this->once())->method('getEntityData')->with($entity)->willReturn(['name' => 'soft']);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);
        $auditService->method('createAuditLog')->willReturn($audit);
        $dispatcher->method('dispatch')->willReturn(true);

        $subscriber->postFlush($args);
    }

    public function testPostFlushRetainsPendingDeletionWhenDispatchFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = self::createStub(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(
            auditService: $auditService,
            dispatcher: $dispatcher,
            accessHandler: $accessHandler,
        );
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $this->auditManager->pendingDeletions = [['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true]];
        $this->auditManager->scheduledAudits = [];

        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditLogInterface::ACTION_DELETE);
        $auditService->method('createAuditLog')->willReturn(new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_DELETE));
        $dispatcher->expects($this->once())->method('dispatch')->willReturn(false);
        $accessHandler->expects($this->never())->method('markAsAudited');
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);

        self::assertNotEmpty($this->auditManager->pendingDeletions);
        self::assertSame($entity, $this->auditManager->pendingDeletions[0]['entity']);
    }

    public function testPostFlushRetriesRetainedPendingDeletionOnNextFlushWithoutDuplication(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = self::createStub(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(
            auditService: $auditService,
            dispatcher: $dispatcher,
            accessHandler: $accessHandler,
        );
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_DELETE);
        $this->auditManager->pendingDeletions = [['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true]];
        $this->auditManager->scheduledAudits = [];

        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditLogInterface::ACTION_DELETE);
        $auditService->method('createAuditLog')->willReturn($audit);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->with($audit, $em, 'post_flush', null, $entity)
            ->willReturnOnConsecutiveCalls(false, true);
        $accessHandler->expects($this->once())->method('markAsAudited');
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);
        self::assertNotEmpty($this->auditManager->pendingDeletions);

        $subscriber->postFlush($args);
        self::assertEmpty($this->auditManager->pendingDeletions);
    }

    public function testPostFlushProcessScheduledAuditsWithStaticId(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '123', AuditLogInterface::ACTION_CREATE);

        $this->auditManager->scheduledAudits = [
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ];

        $this->idResolver->method('resolveFromEntity')->willReturn('456');
        $this->dispatcher->method('dispatch')->willReturn(true);

        $this->subscriber->postFlush($args);

        self::assertSame('456', $audit->entityId);
    }

    public function testReset(): void
    {
        $accessHandler = $this->createMock(AuditAccessHandler::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);
        $accessHandler->expects($this->once())->method('reset');
        $subscriber->reset();
    }

    public function testRecursionPreventionViaIsFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [
            ['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false],
        ];

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->willReturn(true);

        $em->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use ($args) {
                $this->subscriber->postFlush($args);
            });

        $this->subscriber->postFlush($args);
    }

    public function testOnFlushIsSkippedDuringAuditFollowUpFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $onFlushArgs = self::createStub(OnFlushEventArgs::class);
        $postFlushArgs = self::createStub(PostFlushEventArgs::class);
        $dispatcher = self::createStub(AuditDispatcherInterface::class);
        $entityProcessor = $this->createMock(EntityProcessorInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, entityProcessor: $entityProcessor);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [
            ['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false],
        ];

        $postFlushArgs->method('getObjectManager')->willReturn($em);
        $onFlushArgs->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);
        $dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);

        $entityProcessor->expects($this->never())->method('processInsertions');
        $entityProcessor->expects($this->never())->method('processUpdates');
        $entityProcessor->expects($this->never())->method('processCollectionUpdates');
        $entityProcessor->expects($this->never())->method('processDeletions');

        $em->expects($this->once())
            ->method('flush')
            ->willReturnCallback(static function () use ($subscriber, $onFlushArgs): void {
                $subscriber->onFlush($onFlushArgs);
            });

        $subscriber->postFlush($postFlushArgs);
    }

    public function testPostFlushSkipsNestedInvocationTriggeredDuringDispatch(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = self::createStub(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $subscriber = $this->createSubscriber(auditService: $auditService, dispatcher: $dispatcher);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_DELETE);
        $this->auditManager->pendingDeletions = [['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true]];
        $this->auditManager->scheduledAudits = [];

        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditLogInterface::ACTION_DELETE);
        $auditService->method('createAuditLog')->willReturn($audit);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($audit, $em, 'post_flush', null, $entity)
            ->willReturnCallback(static function () use ($subscriber, $args): bool {
                $subscriber->postFlush($args);

                return true;
            });
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);
    }

    public function testPostFlushSkipsNestedInvocationTriggeredByFallbackFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = self::createStub(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $subscriber = $this->createSubscriber(auditService: $auditService, dispatcher: $dispatcher);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_DELETE);
        $this->auditManager->pendingDeletions = [['entity' => $entity, 'data' => ['id' => 1], 'is_managed' => true]];
        $this->auditManager->scheduledAudits = [];

        $this->changeProcessor->method('determineDeletionAction')->willReturn(AuditLogInterface::ACTION_DELETE);
        $auditService->method('createAuditLog')->willReturn($audit);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($audit, $em, 'post_flush', null, $entity)
            ->willReturnCallback(static function () use ($subscriber, $args, $em): bool {
                $em->flush();
                $subscriber->postFlush($args);

                return true;
            });
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->exactly(2))->method('flush');

        $subscriber->postFlush($args);
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
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);
        $em->expects($this->once())->method('flush');
        $logger->expects($this->never())->method('critical');

        $subscriber->postFlush($args);
    }

    public function testPostFlushFlushExceptionWithoutLogger(): void
    {
        $subscriber = $this->createSubscriberWithLogger(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);
        $em->method('flush')->willThrowException(new Exception('Flush failed'));
        $em->method('isOpen')->willReturn(true);

        $subscriber->postFlush($args);
    }

    public function testPostFlushFlushExceptionLogsCorrectContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriberWithLogger($logger);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);
        $em->method('flush')->willThrowException(new Exception('Critical error'));
        $em->method('isOpen')->willReturn(true);

        $logger->expects($this->once())
            ->method('critical')
            ->with('Failed to flush audits', self::callback(static function (array $context): bool {
                return isset($context['exception']) && $context['exception'] === 'Critical error';
            }));

        $subscriber->postFlush($args);
    }

    public function testPostFlushThrowsAuditExceptionWhenEmClosed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriberWithLogger($logger);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $this->auditManager->scheduledAudits = [['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]];

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->method('contains')->with($audit)->willReturn(true);
        $em->method('flush')->willThrowException(new Exception('Connection failed'));
        $em->method('isOpen')->willReturn(false);

        $logger->expects($this->once())->method('critical');

        $this->expectException(AuditException::class);
        $this->expectExceptionMessage('Database flush failed during audit logging, destroying the EntityManager. Original error: Connection failed');

        $subscriber->postFlush($args);
    }

    private function createSubscriberWithLogger(?LoggerInterface $logger): AuditSubscriber
    {
        return $this->createSubscriber(logger: $logger);
    }

    private function createSubscriber(
        ?AuditServiceInterface $auditService = null,
        ?AuditDispatcherInterface $dispatcher = null,
        ?EntityProcessorInterface $entityProcessor = null,
        ?AuditAccessHandler $accessHandler = null,
        ?LoggerInterface $logger = null,
    ): AuditSubscriber {
        return new AuditSubscriber(
            $auditService ?? $this->auditService,
            $this->changeProcessor,
            $dispatcher ?? $this->dispatcher,
            $this->auditManager,
            $entityProcessor ?? $this->entityProcessor,
            $this->transactionIdGenerator,
            $accessHandler ?? $this->accessHandler,
            $this->idResolver,
            $logger ?? $this->logger,
        );
    }
}
