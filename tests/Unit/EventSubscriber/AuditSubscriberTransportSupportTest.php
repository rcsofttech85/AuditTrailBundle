<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(AuditSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
class AuditSubscriberTransportSupportTest extends TestCase
{
    public function testOnFlushDefersWhenTransportDoesNotSupportIt(): void
    {
        $auditService = self::createStub(AuditService::class);
        $changeProcessor = new ChangeProcessor($auditService, true, 'deletedAt');
        $transport = $this->createMock(AuditTransportInterface::class);
        $dispatcher = new AuditDispatcher($transport, null);
        $auditManager = new ScheduledAuditManager(self::createStub(
            EventDispatcherInterface::class
        ));

        $entityProcessor = new EntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            false // deferTransportUntilCommit = false
        );

        $subscriber = new AuditSubscriber(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            $entityProcessor
        );

        $transport->method('supports')->willReturnCallback(fn ($phase) => match ($phase) {
            'on_flush' => false,
            'post_flush' => true,
            default => false,
        });

        $entity = new \stdClass();
        $auditLog = new AuditLog();
        $auditLog->setAction('update');

        $auditService->method('shouldAudit')->willReturn(true);
        $auditService->method('createAuditLog')->willReturn($auditLog);
        $auditService->method('getEntityId')->willReturn('123');

        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturn(['field' => ['old', 'new']]);

        $transport->expects($this->once())
            ->method('send')
            ->with($auditLog, self::callback(fn ($context) => 'post_flush' === ($context['phase'] ?? '')));

        $subscriber->onFlush(new OnFlushEventArgs($em));
        $subscriber->postFlush(new PostFlushEventArgs($em));
    }
}
