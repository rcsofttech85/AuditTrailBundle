<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeIndexBuilder;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\DeferredCollectionDetector;
use Rcsofttech\AuditTrailBundle\Service\EntityAuditDispatchManager;
use Rcsofttech\AuditTrailBundle\Service\EntityInsertionProcessor;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use stdClass;

final class EntityInsertionProcessorTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private AuditQueueManagerInterface&MockObject $auditManager;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->auditManager = $this->createMock(AuditQueueManagerInterface::class);
    }

    public function testDispatchesImmediateInsertionsWithoutDeferredCollections(): void
    {
        $em = self::createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $metadata = self::createStub(ClassMetadata::class);
        $entity = new stdClass();
        $data = ['name' => 'Example'];
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $dispatcher = $this->createMock(AuditDispatcherInterface::class);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $em->expects($this->once())->method('getClassMetadata')->with($entity::class)->willReturn($metadata);
        $metadata->method('getAssociationNames')->willReturn([]);

        $this->auditService->method('getEntityData')->willReturn($data);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Create, $data)
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditAction::Create, null, $data, [], $em)
            ->willReturn($audit);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturn(true);
        $this->auditManager->expects($this->never())->method('schedule');
        $this->auditManager->expects($this->never())->method('schedulePendingAuditPlan');

        $this->createProcessor(dispatcher: $dispatcher)->process($em, $uow);
    }

    public function testSchedulesPendingPlanWhenInsertionHasDeferredCollections(): void
    {
        $em = self::createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $metadata = self::createMock(ClassMetadata::class);
        $entity = new stdClass();
        $pendingRelatedEntity = new stdClass();
        $idResolver = $this->createMock(EntityIdResolverInterface::class);

        $uow->method('getScheduledEntityInsertions')->willReturn([$entity]);
        $em->expects($this->exactly(2))->method('getClassMetadata')->with($entity::class)->willReturn($metadata);
        $metadata->method('getAssociationNames')->willReturn(['items']);
        $metadata->expects($this->once())->method('isCollectionValuedAssociation')->with('items')->willReturn(true);
        $metadata->expects($this->once())->method('getFieldValue')->with($entity, 'items')->willReturn([$pendingRelatedEntity]);

        $idResolver->expects($this->once())
            ->method('resolveFromEntity')
            ->with($pendingRelatedEntity, $em)
            ->willReturn(null);
        $this->auditService->method('getEntityData')->willReturn([]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Create, [])
            ->willReturn(true);
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->auditManager->expects($this->once())
            ->method('schedulePendingAuditPlan')
            ->with(self::callback(static function (PendingAuditPlan $plan) use ($entity): bool {
                return $plan->entity === $entity
                    && $plan->action === AuditAction::Create
                    && $plan->refreshEntityData;
            }));

        $this->createProcessor(idResolver: $idResolver)->process($em, $uow);
    }

    private function createProcessor(
        ?AuditDispatcherInterface $dispatcher = null,
        ?EntityIdResolverInterface $idResolver = null,
    ): EntityInsertionProcessor {
        $resolver = $idResolver ?? self::createStub(EntityIdResolverInterface::class);
        $collectionIdExtractor = new CollectionIdExtractor($resolver);
        $collectionChangeResolver = new CollectionChangeResolver(
            $collectionIdExtractor,
            new CollectionChangeIndexBuilder(
                $collectionIdExtractor,
                new JoinTableCollectionIdLoader($resolver),
            ),
            new JoinTableCollectionIdLoader($resolver),
        );

        return new EntityInsertionProcessor(
            $this->auditService,
            $this->auditManager,
            new DeferredCollectionDetector($collectionChangeResolver),
            new EntityAuditDispatchManager($dispatcher ?? self::createStub(AuditDispatcherInterface::class), $this->auditManager),
        );
    }
}
