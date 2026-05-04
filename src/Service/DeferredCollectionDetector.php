<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DeferredCollectionDetector
{
    public function __construct(
        private CollectionChangeResolver $collectionChangeResolver,
    ) {
    }

    public function entityHasDeferredCollectionAssociations(object $entity, EntityManagerInterface $em): bool
    {
        $metadata = $em->getClassMetadata($entity::class);

        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isCollectionValuedAssociation($field)) {
                continue;
            }

            if ($this->shouldDeferCollectionFieldMaterialization($entity, $field, $em)) {
                return true;
            }
        }

        return false;
    }

    public function shouldDeferCollectionFieldMaterialization(
        object $entity,
        string $field,
        EntityManagerInterface $em,
    ): bool {
        $value = $em->getClassMetadata($entity::class)->getFieldValue($entity, $field);

        return $this->collectionChangeResolver->collectionContainsPendingIds($value, $em);
    }
}
