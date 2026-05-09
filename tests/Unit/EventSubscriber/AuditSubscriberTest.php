<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\AuditedEntityMarker;
use Rcsofttech\AuditTrailBundle\Service\AuditLifecycleState;
use Rcsofttech\AuditTrailBundle\Service\AuditOnFlushProcessor;
use Rcsofttech\AuditTrailBundle\Service\AuditPostFlushProcessor;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\PendingAuditPlanMaterializer;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use ReflectionAttribute;
use ReflectionClass;
use stdClass;
use Symfony\Component\Uid\Factory\UuidFactory;

final class AuditSubscriberTest extends TestCase
{
    private AuditServiceInterface&Stub $auditService;

    private AuditDispatcherInterface&Stub $dispatcher;

    private MockScheduledAuditManager $auditManager;

    private EntityProcessorInterface&Stub $entityProcessor;

    private TransactionIdGenerator $transactionIdGenerator;

    private LoggerInterface&Stub $logger;

    private AuditAccessHandlerInterface&Stub $accessHandler;

    private EntityIdResolverInterface&Stub $idResolver;

    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = self::createStub(AuditServiceInterface::class);
        $this->dispatcher = self::createStub(AuditDispatcherInterface::class);
        $this->auditManager = new MockScheduledAuditManager();
        $this->entityProcessor = self::createStub(EntityProcessorInterface::class);
        $this->transactionIdGenerator = new TransactionIdGenerator(new UuidFactory());
        $this->logger = self::createStub(LoggerInterface::class);
        $this->accessHandler = self::createStub(AuditAccessHandlerInterface::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);

        $this->subscriber = $this->createSubscriber();
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->subscriber->isEnabled());
    }

    public function testDoctrineListenerAttributesPreferRunningAfterExtensionListeners(): void
    {
        $attributes = new ReflectionClass(AuditSubscriber::class)->getAttributes(AsDoctrineListener::class);
        $listenerConfig = array_map(
            static fn (ReflectionAttribute $attribute): array => $attribute->getArguments(),
            $attributes,
        );

        self::assertContains(
            ['event' => Events::onFlush, 'priority' => -1000],
            $listenerConfig,
        );
        self::assertContains(
            ['event' => Events::postFlush],
            $listenerConfig,
        );
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
        $em = self::createStub(EntityManagerInterface::class);
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
        $em = self::createStub(EntityManagerInterface::class);
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
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Delete);

        $this->auditManager->seedScheduledAudits([]);
        $this->auditManager->seedPendingDeletions([
            ['entity' => $entity, 'data' => ['id' => 1], 'action' => AuditAction::Delete],
        ]);

        $auditService->method('createAuditLog')->willReturn($audit);

        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');

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
        $audit = new AuditLog(stdClass::class, null, AuditAction::Create);

        $this->auditManager->seedPendingDeletions([]);
        $this->auditManager->seedScheduledAudits([
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ]);

        $this->idResolver->method('resolveFromEntity')->willReturn('123');

        $dispatcher->expects($this->once())->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);

        self::assertSame('123', $audit->entityId);
    }

    public function testPostFlushRetainsScheduledAuditWhenDispatchFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, accessHandler: $accessHandler);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedPendingDeletions([]);
        $this->auditManager->seedScheduledAudits([
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => false],
        ]);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($audit, $em, AuditPhase::PostFlush, null, $entity)
            ->willReturn(false);
        $accessHandler->expects($this->never())->method('markAsAudited');
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);

        self::assertNotEmpty($this->auditManager->getScheduledAudits());
        self::assertSame($audit, $this->auditManager->getScheduledAudits()[0]->audit);
    }

    public function testPostFlushRetriesRetainedScheduledAuditOnNextFlushWithoutDuplication(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = self::createStub(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, accessHandler: $accessHandler);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedPendingDeletions([]);
        $this->auditManager->seedScheduledAudits([
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => false],
        ]);

        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->with($audit, $em, AuditPhase::PostFlush, null, $entity)
            ->willReturnOnConsecutiveCalls(false, true);
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);
        self::assertTrue($this->auditManager->hasScheduledAudits());

        $subscriber->postFlush($args);
        self::assertFalse($this->auditManager->hasScheduledAudits());
    }

    public function testPostFlushDoesNotTriggerFollowUpFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $dispatcher = self::createStub(AuditDispatcherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, logger: $logger);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);

        $this->auditManager->seedPendingDeletions([]);
        $this->auditManager->seedScheduledAudits([
            [
                'entity' => new stdClass(),
                'audit' => $audit,
                'is_insert' => false,
            ],
        ]);

        $dispatcher->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');
        $logger->expects($this->never())->method('critical');

        $subscriber->postFlush($args);
    }

    public function testOnClear(): void
    {
        $this->auditManager->seedScheduledAudits([
            ['entity' => new stdClass(), 'audit' => new AuditLog(stdClass::class, '1', 'create'), 'is_insert' => true],
        ]);
        $this->subscriber->onClear();
        self::assertEmpty($this->auditManager->getScheduledAudits());
    }

    public function testPostLoadDisabled(): void
    {
        $this->auditManager->disable();

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);
        $accessHandler->expects($this->never())->method('handleAccess');

        $subscriber->postLoad($args);
    }

    public function testPostLoadEnabled(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);

        $accessHandler->expects($this->once())->method('handleAccess')->with($entity, $em);

        $subscriber->postLoad($args);
    }

    public function testPostLoadIsSkippedDuringOnFlushProcessing(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);

        $lifecycleState = new AuditLifecycleState();
        $lifecycleState->beginOnFlush();
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler, lifecycleState: $lifecycleState);

        $accessHandler->expects($this->never())->method('handleAccess');

        $subscriber->postLoad($args);
    }

    public function testPostLoadIsSkippedDuringPostFlushProcessing(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $lifecycleState = new AuditLifecycleState();
        $lifecycleState->beginPostFlush();
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler, lifecycleState: $lifecycleState);

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

        $this->auditManager->seedPendingDeletions([['entity' => new stdClass(), 'data' => [], 'action' => AuditAction::Delete]]);
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

        $this->auditManager->seedPendingDeletions([]);
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
        $this->auditManager->seedPendingDeletions([['entity' => $entity, 'data' => ['id' => 1], 'action' => AuditAction::SoftDelete]]);
        $auditService->expects($this->once())->method('getEntityData')->with($entity, [], $em)->willReturn(['name' => 'soft']);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::SoftDelete);
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
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(
            auditService: $auditService,
            dispatcher: $dispatcher,
            accessHandler: $accessHandler,
        );
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $this->auditManager->seedPendingDeletions([['entity' => $entity, 'data' => ['id' => 1], 'action' => AuditAction::Delete]]);
        $this->auditManager->seedScheduledAudits([]);

        $auditService->method('createAuditLog')->willReturn(new AuditLog(stdClass::class, '1', AuditAction::Delete));
        $dispatcher->expects($this->once())->method('dispatch')->willReturn(false);
        $accessHandler->expects($this->never())->method('markAsAudited');
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);

        self::assertNotEmpty($this->auditManager->getPendingDeletions());
        self::assertSame($entity, $this->auditManager->getPendingDeletions()[0]->entity);
    }

    public function testPostFlushRetriesRetainedPendingDeletionOnNextFlushWithoutDuplication(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = $this->createMock(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = self::createStub(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(
            auditService: $auditService,
            dispatcher: $dispatcher,
            accessHandler: $accessHandler,
        );
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Delete);
        $this->auditManager->seedPendingDeletions([['entity' => $entity, 'data' => ['id' => 1], 'action' => AuditAction::Delete]]);
        $this->auditManager->seedScheduledAudits([]);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $auditService->expects($this->once())->method('createAuditLog')->willReturn($audit);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->with($audit, $em, AuditPhase::PostFlush, null, $entity)
            ->willReturnOnConsecutiveCalls(false, true);
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);
        self::assertTrue($this->auditManager->hasPendingDeletions());
        self::assertSame($audit, $this->auditManager->getPendingDeletions()[0]->audit);

        $subscriber->postFlush($args);
        self::assertFalse($this->auditManager->hasPendingDeletions());
    }

    public function testPostFlushRetriesRetainedPendingAuditPlanOnNextFlushWithoutRematerializingAudit(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $auditService = $this->createMock(AuditServiceInterface::class);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $accessHandler = self::createStub(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(
            auditService: $auditService,
            dispatcher: $dispatcher,
            accessHandler: $accessHandler,
        );
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $this->auditManager->schedulePendingAuditPlan(PendingAuditPlan::forEntityRefresh($entity, AuditAction::Create));

        $auditService->expects($this->once())->method('getEntityData')->with($entity, [], $em)->willReturn(['id' => 1]);
        $auditService->expects($this->once())->method('createAuditLog')->with(
            $entity,
            AuditAction::Create,
            null,
            ['id' => 1],
            [],
            $em,
        )->willReturn($audit);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->with($audit, $em, AuditPhase::PostFlush, null, $entity)
            ->willReturnOnConsecutiveCalls(false, true);
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);
        self::assertTrue($this->auditManager->hasPendingAuditPlans());
        self::assertSame($audit, $this->auditManager->getPendingAuditPlans()[0]->audit);

        $subscriber->postFlush($args);
        self::assertFalse($this->auditManager->hasPendingAuditPlans());
    }

    public function testPostFlushProcessScheduledAuditsWithStaticId(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $entity = new stdClass();
        $audit = new AuditLog(stdClass::class, '123', AuditAction::Create);

        $this->auditManager->seedScheduledAudits([
            ['entity' => $entity, 'audit' => $audit, 'is_insert' => true],
        ]);

        $this->idResolver->method('resolveFromEntity')->willReturn('456');
        $this->dispatcher->method('dispatch')->willReturn(true);

        $this->subscriber->postFlush($args);

        self::assertSame('456', $audit->entityId);
    }

    public function testReset(): void
    {
        $accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
        $subscriber = $this->createSubscriber(accessHandler: $accessHandler);
        $accessHandler->expects($this->once())->method('reset');
        $subscriber->reset();
    }

    public function testRecursionPreventionViaIsFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedScheduledAudits([
            ['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false],
        ]);

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');

        $this->subscriber->postFlush($args);
    }

    public function testOnFlushRunsNormallyWithoutAuditFollowUpFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $onFlushArgs = self::createStub(OnFlushEventArgs::class);
        $postFlushArgs = self::createStub(PostFlushEventArgs::class);
        $dispatcher = self::createStub(AuditDispatcherInterface::class);
        $entityProcessor = $this->createMock(EntityProcessorInterface::class);
        $subscriber = $this->createSubscriber(dispatcher: $dispatcher, entityProcessor: $entityProcessor);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedScheduledAudits([
            ['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false],
        ]);

        $postFlushArgs->method('getObjectManager')->willReturn($em);
        $onFlushArgs->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);
        $dispatcher->method('dispatch')->willReturn(true);

        $entityProcessor->expects($this->once())->method('processInsertions');
        $entityProcessor->expects($this->once())->method('processUpdates');
        $entityProcessor->expects($this->once())->method('processCollectionUpdates');
        $entityProcessor->expects($this->once())->method('processDeletions');
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($postFlushArgs);
        $subscriber->onFlush($onFlushArgs);
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
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Delete);
        $this->auditManager->seedPendingDeletions([['entity' => $entity, 'data' => ['id' => 1], 'action' => AuditAction::Delete]]);
        $this->auditManager->seedScheduledAudits([]);

        $auditService->method('createAuditLog')->willReturn($audit);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($audit, $em, AuditPhase::PostFlush, null, $entity)
            ->willReturnCallback(static function () use ($subscriber, $args): bool {
                $subscriber->postFlush($args);

                return true;
            });
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);
    }

    public function testPostFlushWithLoggerButNoException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriber(logger: $logger);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedScheduledAudits([['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]]);

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');
        $logger->expects($this->never())->method('critical');

        $subscriber->postFlush($args);
    }

    public function testPostFlushWithoutLoggerStillAvoidsFollowUpFlush(): void
    {
        $subscriber = $this->createSubscriber(logger: null);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedScheduledAudits([['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]]);

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');

        $subscriber->postFlush($args);

        self::assertEmpty($this->auditManager->getScheduledAudits());
    }

    public function testPostFlushDoesNotLogFlushFailureContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriber(logger: $logger);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedScheduledAudits([['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]]);

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');
        $logger->expects($this->never())->method('critical');

        $subscriber->postFlush($args);
    }

    public function testPostFlushDoesNotThrowWhenEntityManagerWouldPreviouslyCloseOnFollowUpFlush(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = $this->createSubscriber(logger: $logger);

        $em = $this->createMock(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $this->auditManager->seedScheduledAudits([['entity' => new stdClass(), 'audit' => $audit, 'is_insert' => false]]);

        $this->dispatcher->method('dispatch')->willReturn(true);
        $em->expects($this->never())->method('flush');
        $logger->expects($this->never())->method('critical');

        $subscriber->postFlush($args);

        self::assertEmpty($this->auditManager->getScheduledAudits());
    }

    private function createSubscriber(
        ?AuditServiceInterface $auditService = null,
        ?AuditDispatcherInterface $dispatcher = null,
        ?EntityProcessorInterface $entityProcessor = null,
        ?AuditAccessHandlerInterface $accessHandler = null,
        ?LoggerInterface $logger = null,
        ?AuditLifecycleState $lifecycleState = null,
    ): AuditSubscriber {
        $resolvedAccessHandler = $accessHandler ?? $this->accessHandler;
        $resolvedIdResolver = $this->idResolver;
        $resolvedLifecycleState = $lifecycleState ?? new AuditLifecycleState();
        $associationImpactAnalyzer = new AssociationImpactAnalyzer(
            new CollectionIdExtractor(self::createStub(EntityIdResolverInterface::class)),
            new CollectionTransitionMerger(),
        );

        return new AuditSubscriber(
            $this->auditManager,
            $resolvedAccessHandler,
            $resolvedLifecycleState,
            new AuditOnFlushProcessor($entityProcessor ?? $this->entityProcessor, $associationImpactAnalyzer),
            new AuditPostFlushProcessor(
                $auditService ?? $this->auditService,
                $dispatcher ?? $this->dispatcher,
                $this->auditManager,
                $this->auditManager,
                new PendingAuditPlanMaterializer(
                    $auditService ?? $this->auditService,
                    new CollectionIdExtractor($resolvedIdResolver),
                ),
                $this->transactionIdGenerator,
                new AuditedEntityMarker($resolvedAccessHandler, $resolvedIdResolver),
                $logger ?? $this->logger,
            ),
        );
    }
}
