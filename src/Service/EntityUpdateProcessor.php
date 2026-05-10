<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;

use function is_array;

final readonly class EntityUpdateProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditQueueManagerInterface $auditManager,
        private AssociationImpactAnalyzer $associationImpactAnalyzer,
        private DeferredCollectionDetector $deferredCollectionDetector,
        private EntityUpdateTransitionResolver $updateTransitionResolver,
        private EntityAuditDispatchManager $dispatchManager,
    ) {
    }

    /**
     * @param list<AssociationImpact>|null $deletedAssociationImpacts
     */
    public function process(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $deletedAssociationImpacts ??= $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($em, $uow);
        $deletedAssociationImpactsByOwner = $this->updateTransitionResolver->indexDeletedAssociationImpacts($deletedAssociationImpacts);
        $collectionChangesByOwner = $this->updateTransitionResolver->indexCollectionChanges($em, $uow);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->processScheduledEntityUpdate(
                $entity,
                $em,
                $uow,
                $deletedAssociationImpactsByOwner,
                $collectionChangesByOwner,
            );
        }
    }

    /**
     * @param array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>> $deletedAssociationImpactsByOwner
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}>                    $collectionChangesByOwner
     */
    private function processScheduledEntityUpdate(
        object $entity,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        array $deletedAssociationImpactsByOwner,
        array $collectionChangesByOwner,
    ): void {
        $transition = $this->updateTransitionResolver->resolve(
            $entity,
            $uow,
            $deletedAssociationImpactsByOwner,
            $collectionChangesByOwner,
        );
        $old = $transition['old'];
        $new = $transition['new'];
        if ($old === [] && $new === []) {
            return;
        }

        $action = $transition['action'];
        if (!$this->auditService->shouldAudit($entity, $action, $new)) {
            return;
        }

        $deferredCollectionFields = $this->resolveDeferredCollectionFields($entity, $new, $em);
        if ($deferredCollectionFields === []) {
            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new, [], $em);
            $this->dispatchManager->dispatchOrSchedule($audit, $entity, $em, $uow, false);

            return;
        }

        $this->scheduleDeferredCollectionAuditPlan($entity, $action, $old, $new, $deferredCollectionFields);
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     * @param list<string>         $deferredCollectionFields
     */
    private function scheduleDeferredCollectionAuditPlan(
        object $entity,
        \Rcsofttech\AuditTrailBundle\Enum\AuditAction $action,
        array $oldValues,
        array $newValues,
        array $deferredCollectionFields,
    ): void {
        // Strip deferred collection fields from the immediate payload so only eagerly materialized changes are stored now.
        foreach ($deferredCollectionFields as $field) {
            unset($newValues[$field]);
        }

        $this->auditManager->schedulePendingAuditPlan(
            PendingAuditPlan::forDeferredCollections($entity, $action, $oldValues, $newValues, $deferredCollectionFields),
        );
    }

    /**
     * @param array<string, mixed> $newValues
     *
     * @return list<string>
     */
    private function resolveDeferredCollectionFields(object $entity, array $newValues, EntityManagerInterface $em): array
    {
        $deferredCollectionFields = [];

        foreach ($newValues as $field => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (!$this->deferredCollectionDetector->shouldDeferCollectionFieldMaterialization($entity, $field, $em)) {
                continue;
            }

            $deferredCollectionFields[] = $field;
        }

        return $deferredCollectionFields;
    }
}
