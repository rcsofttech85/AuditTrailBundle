<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;

class AuditSubscriberTest extends TestCase
{
    private AuditService $auditService;
    private ChangeProcessor $changeProcessor;
    private AuditDispatcher $dispatcher;
    private ScheduledAuditManager $auditManager;
    private EntityProcessor $entityProcessor;
    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = self::createStub(AuditService::class);
        $this->changeProcessor = self::createStub(ChangeProcessor::class);
        $this->dispatcher = self::createStub(AuditDispatcher::class);
        $this->auditManager = self::createStub(ScheduledAuditManager::class);
        $this->entityProcessor = self::createStub(EntityProcessor::class);

        $this->subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $this->entityProcessor
        );
    }

    public function testOnFlushDelegatesToEntityProcessor(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $args = self::createStub(OnFlushEventArgs::class);
        $entityProcessor = self::createMock(EntityProcessor::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $entityProcessor->expects($this->once())->method('processInsertions')->with($em, $uow);
        $entityProcessor->expects($this->once())->method('processUpdates')->with($em, $uow);
        $entityProcessor->expects($this->once())->method('processCollectionUpdates')->with($em, $uow, self::anything());
        $entityProcessor->expects($this->once())->method('processDeletions')->with($em, $uow);

        $this->subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $this->auditManager,
            $entityProcessor
        );

        $this->subscriber->onFlush($args);
    }

    public function testPostFlushDelegatesToAuditManagerAndDispatcher(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $args = self::createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $auditLog = self::createStub(\Rcsofttech\AuditTrailBundle\Entity\AuditLog::class);
        $auditManager = self::createMock(ScheduledAuditManager::class);
        $dispatcher = self::createMock(AuditDispatcher::class);

        $auditManager->method('getScheduledAudits')->willReturn([
            [
                'entity' => new \stdClass(),
                'audit' => $auditLog,
                'is_insert' => false,
            ],
        ]);
        $auditManager->method('getPendingDeletions')->willReturn([]);

        $dispatcher->expects($this->once())->method('dispatch')->with($auditLog, $em, 'post_flush');
        $auditManager->expects($this->once())->method('clear');

        $this->subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $dispatcher,
            $auditManager,
            $this->entityProcessor
        );

        $this->subscriber->postFlush($args);
    }

    public function testResetClearsAuditManager(): void
    {
        $auditManager = self::createMock(ScheduledAuditManager::class);
        $auditManager->expects($this->once())->method('clear');
        $this->subscriber = new AuditSubscriber(
            $this->auditService,
            $this->changeProcessor,
            $this->dispatcher,
            $auditManager,
            $this->entityProcessor
        );
        $this->subscriber->reset();
    }
}
