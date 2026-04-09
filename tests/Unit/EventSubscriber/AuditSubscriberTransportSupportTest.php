<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use stdClass;

final class AuditSubscriberTransportSupportTest extends AbstractAuditTestCase
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
                self::callback(static fn (AuditTransportContext $context): bool => $context->phase === AuditPhase::PostFlush)
            );

        $subscriber->onFlush(new OnFlushEventArgs($em));
        $subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testOnFlushDispatchesImmediatelyWhenTransportSupportsIt(): void
    {
        /** @var AuditTransportInterface&MockObject $transport */
        $transport = $this->createMock(AuditTransportInterface::class);
        $auditService = $this->createAuditServiceStub();
        $subscriber = $this->createSubscriber($transport, $auditService);
        $em = $this->createMockEntityManagerWithUow();

        $transport->method('supports')->willReturnCallback(
            static fn (AuditTransportContext $context): bool => match ($context->phase) {
                AuditPhase::OnFlush => true,
                AuditPhase::PostFlush => false,
                default => false,
            }
        );
        $transport->expects($this->once())
            ->method('send')
            ->with(self::callback(static fn (AuditTransportContext $context): bool => $context->phase === AuditPhase::OnFlush));

        $subscriber->onFlush(new OnFlushEventArgs($em));
        $subscriber->postFlush(new PostFlushEventArgs($em));
    }

    private function createSubscriber(
        AuditTransportInterface&MockObject $transport,
        AuditServiceInterface $auditService,
    ): AuditSubscriber {
        $changeProcessor = new ChangeProcessor(
            self::createStub(AuditMetadataManagerInterface::class),
            new ValueSerializer(self::createStub(EntityIdResolverInterface::class)),
            true,
            'deletedAt'
        );

        $dispatcher = $this->createAuditDispatcher($transport);
        $auditManager = new ScheduledAuditManager();

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
            new AssociationImpactAnalyzer(new CollectionIdExtractor(self::createStub(EntityIdResolverInterface::class)), new CollectionTransitionMerger()),
            new TransactionIdGenerator(),
            self::createStub(AuditAccessHandlerInterface::class),
            self::createStub(EntityIdResolverInterface::class)
        );
    }

    private function createAuditServiceStub(): AuditServiceInterface
    {
        $auditLog = new AuditLog(stdClass::class, '123', 'update');

        $auditService = self::createStub(AuditServiceInterface::class);
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
            static fn (AuditTransportContext $context): bool => match ($context->phase) {
                AuditPhase::OnFlush => false,
                AuditPhase::PostFlush => true,
                default => false,
            }
        );
    }
}
