<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;

final readonly class RevertEntityStateApplier
{
    public function __construct(
        private EntityManagerResolver $entityManagerResolver,
        private SoftDeleteHandlerInterface $softDeleteHandler,
        private RevertCollectionAssociationSynchronizer $collectionSynchronizer,
    ) {
    }

    public function apply(object $entity, RevertPlan $revertPlan): void
    {
        $metadata = $this->entityManagerResolver->requireForObject($entity)->getClassMetadata($entity::class);

        foreach ($revertPlan->fieldValues as $field => $value) {
            $this->applyRevertFieldValue($metadata, $entity, $field, $value);
        }

        if ($revertPlan->restoreSoftDelete) {
            $this->softDeleteHandler->restoreSoftDeleted($entity);
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function applyRevertFieldValue(ClassMetadata $metadata, object $entity, string $field, mixed $value): void
    {
        if ($metadata->hasAssociation($field) && $metadata->isCollectionValuedAssociation($field)) {
            $this->collectionSynchronizer->applyCollectionAssociationRevertData($metadata, $entity, $field, $value);

            return;
        }

        $metadata->setFieldValue($entity, $field, $value);
    }
}
