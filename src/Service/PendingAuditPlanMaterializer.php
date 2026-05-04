<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
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
        $oldValues = $plan->oldValues;
        $newValues = $plan->refreshEntityData
            ? $this->auditService->getEntityData($plan->entity, [], $entityManager)
            : $plan->newValues;
        $metadata = $entityManager->getClassMetadata($plan->entity::class);

        foreach ($plan->deferredCollectionFields as $field) {
            $currentValue = $metadata->getFieldValue($plan->entity, $field);
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
