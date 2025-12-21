<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;

class AuditSubscriberTest extends TestCase
{
    private AuditService $auditService;
    private AuditTransportInterface $transport;
    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditService::class);
        $this->transport = $this->createMock(AuditTransportInterface::class);
        $this->subscriber = new AuditSubscriber($this->auditService, $this->transport);
    }

    public function testOnFlushHandlesInsertions(): void
    {
        $entity = new \stdClass();
        $auditLog = new AuditLog();

        $em = $this->createStub(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = $this->createMock(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $this->auditService->method('shouldAudit')->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($auditLog);
        $this->auditService->method('getEntityData')->willReturn([]);

        $this->transport->expects($this->once())
            ->method('send')
            ->with($auditLog, $this->callback(fn($context) => $context['phase'] === 'on_flush'));

        $this->subscriber->onFlush($args);
    }

    public function testPostFlushHandlesPendingAudits(): void
    {
        // First trigger onFlush to populate pending audits
        $entity = new \stdClass();
        $auditLog = new AuditLog();

        $em = $this->createStub(EntityManagerInterface::class);
        $uow = $this->createStub(UnitOfWork::class);
        $args = $this->createStub(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $this->auditService->method('shouldAudit')->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($auditLog);

        $this->subscriber->onFlush($args);

        // Now trigger postFlush
        $postFlushArgs = $this->createStub(PostFlushEventArgs::class);
        $postFlushArgs->method('getObjectManager')->willReturn($em);

        $this->transport->expects($this->once())
            ->method('send')
            ->with($auditLog, $this->callback(fn($context) => $context['phase'] === 'post_flush'));

        $this->subscriber->postFlush($postFlushArgs);
    }
}
