<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(AuditSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
class AuditSubscriberTransportSupportTest extends AbstractAuditTestCase
{
    public function testOnFlushDefersWhenTransportDoesNotSupportIt(): void
    {
        /** @var AuditTransportInterface&MockObject $transport */
        $transport = $this->createMock(AuditTransportInterface::class);
        $auditService = $this->createAuditServiceStub();
        $subscriber = $this->createSubscriber($transport, $auditService);
        $em = $this->createMockEntityManagerWithUow();

        $this->configureTransportPhaseSupport($transport);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::isInstanceOf(AuditLog::class),
                self::callback(static fn (array $context): bool => ($context['phase'] ?? '') === 'post_flush')
            );

        $subscriber->onFlush(new OnFlushEventArgs($em));
        $subscriber->postFlush(new PostFlushEventArgs($em));
    }

    private function createSubscriber(
        AuditTransportInterface&MockObject $transport,
        AuditService $auditService,
    ): AuditSubscriber {
        $changeProcessor = new ChangeProcessor(
            $auditService,
            new ValueSerializer(),
            true,
            'deletedAt'
        );

        $dispatcher = $this->createAuditDispatcher($transport);
        $auditManager = new ScheduledAuditManager(self::createStub(EventDispatcherInterface::class));

        $entityProcessor = $this->createEntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager
        );

        return new AuditSubscriber(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            $entityProcessor,
            self::createStub(TransactionIdGenerator::class),
            self::createStub(UserResolverInterface::class)
        );
    }

    private function createAuditServiceStub(): AuditService
    {
        $auditLog = new AuditLog();
        $auditLog->setAction('update');

        $auditService = self::createStub(AuditService::class);
        $auditService->method('shouldAudit')->willReturn(true);
        $auditService->method('createAuditLog')->willReturn($auditLog);

        return $auditService;
    }

    private function createMockEntityManagerWithUow(): EntityManagerInterface
    {
        $entity = new stdClass();

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 123]);

        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturn(['field' => ['old', 'new']]);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getUnitOfWork')->willReturn($uow);

        return $em;
    }

    /**
     * @param AuditTransportInterface&MockObject $transport
     */
    private function configureTransportPhaseSupport(AuditTransportInterface $transport): void
    {
        $transport->method('supports')->willReturnCallback(
            static fn (string $phase): bool => match ($phase) {
                'on_flush' => false,
                'post_flush' => true,
                default => false,
            }
        );
    }
}
