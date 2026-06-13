<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;

use function is_iterable;

final readonly class PendingAuditPlanMaterializer
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private CollectionIdExtractor $collectionIdExtractor,
    ) {
    }

    public function materialize(PendingAuditPlan $plan, EntityManagerInterface $entityManager): AuditLog
    {
        if ($plan->audit !== null) {
            return $plan->audit;
        }

        $oldValues = $plan->oldValues;
        $newValues = $plan->refreshEntityData
            ? $this->auditService->getEntityData($plan->entity, [], $entityManager)
            : $plan->newValues;
        $metadata = $entityManager->getClassMetadata($plan->entity::class);

        foreach ($plan->deferredCollectionFields as $field) {
            $currentValue = $metadata->getFieldValue($plan->entity, $field);
            if ($currentValue instanceof PersistentCollection && $this->canReadCollectionThroughCriteria($currentValue)) {
                $newValues[$field] = $this->collectionIdExtractor->extractFromPersistentCollectionCriteria(
                    $currentValue,
                    $entityManager,
                );

                continue;
            }

            $newValues[$field] = is_iterable($currentValue)
                ? $this->collectionIdExtractor->extractFromIterable($currentValue, $entityManager)
                : [];
        }

        return $this->auditService->createAuditLog(
            $plan->entity,
            $plan->action,
            $oldValues,
            $newValues,
            $plan->context,
            $entityManager,
        );
    }

    /**
     * During deferred collection materialization, a clean uninitialized
     * collection can be read through Criteria without hydrating the original
     * PersistentCollection. Dirty collections keep the existing path because
     * their in-memory diffs must remain part of the result.
     *
     * @param PersistentCollection<int|string, mixed> $items
     */
    private function canReadCollectionThroughCriteria(PersistentCollection $items): bool
    {
        if ($items->getMapping()->isManyToMany() && !$items->getTypeClass()->isInheritanceTypeNone()) {
            return false;
        }

        return !$items->isInitialized() && !$items->isDirty();
    }
}
