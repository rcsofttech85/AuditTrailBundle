<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;

class AuditSubscriberTest extends TestCase
{
    // - FIXED: Added Stub intersection type for PHPStan
    private AuditService&Stub $auditService;
    private AuditTransportInterface&Stub $transport;
    private AuditSubscriber $subscriber;

    protected function setUp(): void
    {
        // Use stubs by default - individual tests will create mocks if needed
        $this->auditService = $this->createStub(AuditService::class);
        $this->transport = $this->createStub(AuditTransportInterface::class);
        $this->subscriber = new AuditSubscriber($this->auditService, $this->transport);
    }

    public function testOnFlushHandlesInsertions(): void
    {
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
        $this->auditService->method('getEntityData')->willReturn([]);

        // No exceptions = success
        $this->subscriber->onFlush($args);

        // - FIXED: Remove redundant assertion
        $this->expectNotToPerformAssertions();
    }

    public function testPostFlushHandlesPendingAudits(): void
    {
        // Create mock only for this test since we verify send()
        $transport = $this->createMock(AuditTransportInterface::class);
        $subscriber = new AuditSubscriber($this->auditService, $transport);

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
        $this->auditService->method('getEntityId')->willReturn('123');

        $subscriber->onFlush($args);

        // Now trigger postFlush
        $postFlushArgs = $this->createStub(PostFlushEventArgs::class);
        $postFlushArgs->method('getObjectManager')->willReturn($em);

        $transport->expects($this->once())
            ->method('send')
            ->with($auditLog, $this->callback(fn ($context) => 'post_flush' === $context['phase']));

        $subscriber->postFlush($postFlushArgs);
    }

    public function testOnFlushPreventsRecursion(): void
    {
        // Create mock only for this test since we verify never()
        $auditService = $this->createMock(AuditService::class);
        $subscriber = new AuditSubscriber($auditService, $this->transport);

        $entity = new \stdClass();

        $em = $this->createStub(EntityManagerInterface::class);
        $uow = $this->createStub(UnitOfWork::class);
        $args = $this->createStub(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        // Simulate recursion by setting depth
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('recursionDepth');
        $property->setAccessible(true);
        $property->setValue($subscriber, 1);

        // Expect NO calls to createAuditLog if recursion is detected
        $auditService->expects($this->never())->method('createAuditLog');

        $subscriber->onFlush($args);
    }

    public function testResetClearsState(): void
    {
        $reflection = new \ReflectionClass($this->subscriber);

        $scheduledProp = $reflection->getProperty('scheduledAudits');
        $scheduledProp->setAccessible(true);
        $scheduledProp->setValue($this->subscriber, [['entity' => new \stdClass(), 'audit' => new AuditLog()]]);

        $pendingProp = $reflection->getProperty('pendingDeletions');
        $pendingProp->setAccessible(true);
        $pendingProp->setValue($this->subscriber, [['entity' => new \stdClass()]]);

        $this->subscriber->reset();

        $this->assertEmpty($scheduledProp->getValue($this->subscriber));
        $this->assertEmpty($pendingProp->getValue($this->subscriber));
    }

    public function testTransportFailureTriggersDbFallback(): void
    {
        // Create stub for transport (we don't verify method calls)
        $transport = $this->createStub(AuditTransportInterface::class);

        $entity = new \stdClass();
        $auditLog = new AuditLog();

        $em = $this->createMock(EntityManagerInterface::class); // Mock because we verify persist()
        $uow = $this->createStub(UnitOfWork::class);
        $args = $this->createStub(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(false);

        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['name' => ['old', 'new']]);

        $this->auditService->method('shouldAudit')->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn($auditLog);
        $this->auditService->method('getEntityId')->willReturn('123');

        // Transport throws exception
        $transport->method('send')
            ->willThrowException(new \RuntimeException('Transport down'));

        // Subscriber with fallback enabled
        $subscriber = new AuditSubscriber(
            $this->auditService,
            $transport,
            fallbackToDatabase: true,
            deferTransportUntilCommit: false // Process immediately
        );

        // Expect persist to be called for fallback
        $em->expects($this->once())->method('persist')->with($auditLog);

        $subscriber->onFlush($args);
    }

    public function testSoftDeleteDetection(): void
    {
        $entity = new class () {
            public ?\DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = new \DateTimeImmutable();

        $auditLog = new AuditLog();

        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createStub(ClassMetadata::class);
        $reflProp = $this->createStub(\ReflectionProperty::class);

        $args = $this->createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('contains')->willReturn(true);
        $em->method('isOpen')->willReturn(true);

        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getReflectionProperty')->willReturn($reflProp);

        $reflProp->method('getValue')->willReturn($entity->deletedAt);

        $this->auditService->method('shouldAudit')->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn(['id' => 1]);
        $this->auditService->method('createAuditLog')
            ->with(
                $entity,
                AuditLog::ACTION_SOFT_DELETE,
                $this->anything(),
                $this->anything()
            )
            ->willReturn($auditLog);

        $subscriber = new AuditSubscriber(
            $this->auditService,
            $this->transport,
            enableSoftDelete: true
        );

        // Populate pending deletions
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('pendingDeletions');
        $property->setAccessible(true);
        $property->setValue($subscriber, [
            [
                'entity' => $entity,
                'data' => ['id' => 1],
                'is_managed' => true,
            ],
        ]);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);
    }

    public function testHardDeleteDetection(): void
    {
        $entity = new \stdClass();
        $auditLog = new AuditLog();

        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createStub(ClassMetadata::class);

        $args = $this->createStub(PostFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('contains')->willReturn(false); // Not managed = hard delete
        $em->method('isOpen')->willReturn(true);

        $metadata->method('hasField')->willReturn(false); // No soft delete field

        $this->auditService->method('shouldAudit')->willReturn(true);
        $this->auditService->method('getEntityData')->willReturn(['id' => 1]);
        $this->auditService->method('createAuditLog')
            ->with(
                $entity,
                AuditLog::ACTION_DELETE,
                $this->anything(),
                null
            )
            ->willReturn($auditLog);

        $subscriber = new AuditSubscriber(
            $this->auditService,
            $this->transport,
            enableHardDelete: true
        );

        // Populate pending deletions
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('pendingDeletions');
        $property->setAccessible(true);
        $property->setValue($subscriber, [
            [
                'entity' => $entity,
                'data' => ['id' => 1],
                'is_managed' => false,
            ],
        ]);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $subscriber->postFlush($args);
    }

    public function testMaxAuditLimitThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum audit queue size exceeded');

        $em = $this->createStub(EntityManagerInterface::class);
        $uow = $this->createStub(UnitOfWork::class);
        $args = $this->createStub(OnFlushEventArgs::class);

        $args->method('getObjectManager')->willReturn($em);
        $em->method('getUnitOfWork')->willReturn($uow);

        // Create 1001 entities (exceeds MAX_SCHEDULED_AUDITS = 1000)
        $entities = array_fill(0, 1001, new \stdClass());

        $uow->method('getScheduledEntityInsertions')->willReturn($entities);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $this->auditService->method('shouldAudit')->willReturn(true);
        $this->auditService->method('createAuditLog')->willReturn(new AuditLog());
        $this->auditService->method('getEntityData')->willReturn([]);

        $this->subscriber->onFlush($args);
    }
}
