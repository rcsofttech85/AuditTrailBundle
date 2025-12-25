<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AuditSubscriber::class)]
class AuditSubscriberTransportSupportTest extends TestCase
{
    public function testOnFlushDefersWhenTransportDoesNotSupportIt(): void
    {
        // Mocks
        $auditService = $this->createMock(AuditService::class);
        $transport = $this->createMock(AuditTransportInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);

        // Setup Subscriber with deferTransportUntilCommit = false
        $subscriber = new AuditSubscriber(
            $auditService,
            $transport,
            deferTransportUntilCommit: false,
            enabled: true
        );

        // Setup Transport: Does NOT support on_flush
        $transport->method('supports')
            ->willReturnMap([
                ['on_flush', false],
                ['post_flush', true],
            ]);

        // Setup Entity
        $entity = new \stdClass();
        $auditLog = new AuditLog();
        $auditLog->setAction('update');

        // Setup AuditService
        $auditService->method('shouldAudit')->willReturn(true);
        $auditService->method('createAuditLog')->willReturn($auditLog);
        $auditService->method('getEntityId')->willReturn('123');

        // Setup EntityManager & UnitOfWork
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($this->createMock(ClassMetadata::class));
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]); // One update
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['field' => ['old', 'new']]);

        // Expectation: send() should be called EXACTLY ONCE, and ONLY with 'post_flush' phase.
        // If it were called in onFlush, the count would be 2 (or 1 with wrong phase).
        $transport->expects($this->once())
            ->method('send')
            ->with($auditLog, $this->callback(function ($context) {
                return ($context['phase'] ?? '') === 'post_flush';
            }));

        // 1. Trigger onFlush
        $args = new OnFlushEventArgs($em);
        $subscriber->onFlush($args);

        // 2. Trigger postFlush
        $postFlushArgs = new PostFlushEventArgs($em);
        $subscriber->postFlush($postFlushArgs);
    }
}
