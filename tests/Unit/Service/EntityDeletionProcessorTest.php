<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\EntityAuditDispatchManager;
use Rcsofttech\AuditTrailBundle\Service\EntityDeletionProcessor;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;
use stdClass;

final class EntityDeletionProcessorTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private AuditQueueManagerInterface&MockObject $auditManager;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->auditManager = $this->createMock(AuditQueueManagerInterface::class);
    }

    public function testAddsPendingDeletionForAuditedDeletes(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $changeProcessor = $this->createMock(ChangeProcessorInterface::class);

        $uow->method('getScheduledEntityDeletions')->willReturn([$entity]);
        $uow->method('getEntityChangeSet')->willReturn([]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity)
            ->willReturn(true);
        $changeProcessor->expects($this->once())
            ->method('determineDeletionAction')
            ->with($em, $entity, true)
            ->willReturn(AuditAction::Delete);
        $this->auditService->expects($this->once())
            ->method('getEntityData')
            ->with($entity, [], $em)
            ->willReturn(['id' => '1']);
        $em->expects($this->once())->method('contains')->with($entity)->willReturn(true);
        $this->auditManager->expects($this->once())
            ->method('addPendingDeletion')
            ->with($entity, ['id' => '1'], true, AuditAction::Delete);

        $this->createProcessor(changeProcessor: $changeProcessor)->process($em, $uow, []);
    }

    public function testDispatchesRelatedCollectionImpactAuditsBeforeDeletionHandling(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createMock(UnitOfWork::class);
        $relatedEntity = new stdClass();
        $impact = new AssociationImpact($relatedEntity, 'items', ['1', '2'], ['2']);
        $audit = new AuditLog(stdClass::class, '10', AuditAction::Update);
        $changeProcessor = self::createStub(ChangeProcessorInterface::class);

        $uow->expects($this->once())->method('isScheduledForInsert')->with($relatedEntity)->willReturn(false);
        $uow->expects($this->once())->method('isScheduledForUpdate')->with($relatedEntity)->willReturn(false);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($relatedEntity, AuditAction::Update, ['items' => ['2']])
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($relatedEntity, AuditAction::Update, ['items' => ['1', '2']], ['items' => ['2']], [], $em)
            ->willReturn($audit);
        $this->auditManager->expects($this->once())
            ->method('schedule')
            ->with($relatedEntity, $audit, false);

        $this->createProcessor(changeProcessor: $changeProcessor)->process($em, $uow, [$impact]);
    }

    private function createProcessor(
        ?ChangeProcessorInterface $changeProcessor = null,
        ?AuditDispatcherInterface $dispatcher = null,
    ): EntityDeletionProcessor {
        $idResolver = self::createStub(EntityIdResolverInterface::class);

        return new EntityDeletionProcessor(
            $this->auditService,
            $changeProcessor ?? self::createStub(ChangeProcessorInterface::class),
            $this->auditManager,
            new AssociationImpactAnalyzer(new CollectionIdExtractor($idResolver), new CollectionTransitionMerger()),
            new EntityAuditDispatchManager($dispatcher ?? self::createStub(AuditDispatcherInterface::class), $this->auditManager),
            true,
        );
    }
}
