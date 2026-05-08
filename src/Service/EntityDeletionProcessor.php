<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;

use function array_key_exists;
use function is_array;

final readonly class EntityDeletionProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ChangeProcessorInterface $changeProcessor,
        private AuditQueueManagerInterface $auditManager,
        private AssociationImpactAnalyzer $associationImpactAnalyzer,
        private EntityAuditDispatchManager $dispatchManager,
        private bool $enableHardDelete = true,
    ) {
    }

    /**
     * @param list<AssociationImpact>|null $deletedAssociationImpacts
     */
    public function process(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $this->processRelatedEntityCollectionImpacts(
            $deletedAssociationImpacts ?? $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($em, $uow),
            $em,
            $uow,
        );

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $action = $this->resolveDeletionAction($entity, $em, $uow);
            if ($action === null) {
                continue;
            }

            $this->auditManager->addPendingDeletion(
                $entity,
                $this->auditService->getEntityData($entity, [], $em),
                $action,
            );
        }
    }

    private function shouldProcessEntity(object $entity): bool
    {
        return !$entity instanceof AuditLog && $this->auditService->shouldAudit($entity);
    }

    private function resolveDeletionAction(object $entity, EntityManagerInterface $em, UnitOfWork $uow): ?AuditAction
    {
        $changeSet = $uow->getEntityChangeSet($entity);
        if ($this->isSoftDeleteChangeSet($changeSet)) {
            return null;
        }

        return $this->changeProcessor->determineDeletionAction($em, $entity, $this->enableHardDelete);
    }

    /**
     * @param list<AssociationImpact> $impacts
     */
    private function processRelatedEntityCollectionImpacts(
        array $impacts,
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): void {
        foreach ($impacts as $impact) {
            $relatedEntity = $impact->entity;
            if ($uow->isScheduledForInsert($relatedEntity) || $uow->isScheduledForUpdate($relatedEntity)) {
                continue;
            }

            $newValues = [$impact->field => $impact->new];
            if (!$this->auditService->shouldAudit($relatedEntity, AuditAction::Update, $newValues)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $relatedEntity,
                AuditAction::Update,
                [$impact->field => $impact->old],
                $newValues,
                [],
                $em,
            );

            $this->dispatchManager->dispatchOrSchedule($audit, $relatedEntity, $em, $uow, false);
        }
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    private function isSoftDeleteChangeSet(array $changeSet): bool
    {
        if ($changeSet === []) {
            return false;
        }

        foreach ($changeSet as $change) {
            if (!is_array($change) || !array_key_exists(0, $change) || !array_key_exists(1, $change)) {
                return false;
            }
        }

        /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
        return $this->changeProcessor->determineUpdateAction($changeSet) === AuditAction::SoftDelete;
    }
}
