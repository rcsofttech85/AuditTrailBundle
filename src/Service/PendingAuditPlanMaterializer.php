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
            if ($currentValue instanceof PersistentCollection && !$currentValue->isInitialized()) {
                // Do not iterate a lazy collection just to read its ids for the audit
                // log: that initializes it from the database and leaves it frozen for
                // the rest of the request, corrupting later reads such as an
                // EXTRA_LAZY count(). Resolve the ids from the snapshot and diffs.
                $newValues[$field] = $this->collectionIdExtractor->extractIdsWithoutInitializing($currentValue, $entityManager);

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
}
