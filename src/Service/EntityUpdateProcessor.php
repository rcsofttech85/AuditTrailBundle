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
            $transition = $this->updateTransitionResolver->resolve(
                $entity,
                $uow,
                $deletedAssociationImpactsByOwner,
                $collectionChangesByOwner,
            );
            $old = $transition['old'];
            $new = $transition['new'];

            if ($old === [] && $new === []) {
                continue;
            }

            $action = $transition['action'];
            if (!$this->auditService->shouldAudit($entity, $action, $new)) {
                continue;
            }

            $deferredCollectionFields = $this->resolveDeferredCollectionFields($entity, $new, $em);
            if ($deferredCollectionFields === []) {
                $audit = $this->auditService->createAuditLog($entity, $action, $old, $new, [], $em);
                $this->dispatchManager->dispatchOrSchedule($audit, $entity, $em, $uow, false);

                continue;
            }

            // Strip deferred collection fields from the immediate payload so only eagerly materialized changes are stored now.
            foreach ($deferredCollectionFields as $field) {
                unset($new[$field]);
            }

            $this->auditManager->schedulePendingAuditPlan(
                PendingAuditPlan::forDeferredCollections($entity, $action, $old, $new, $deferredCollectionFields),
            );
        }
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
