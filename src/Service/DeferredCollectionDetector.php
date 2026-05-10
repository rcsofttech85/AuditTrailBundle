<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

use function array_any;

final readonly class DeferredCollectionDetector
{
    public function __construct(
        private CollectionChangeResolver $collectionChangeResolver,
    ) {
    }

    public function entityHasDeferredCollectionAssociations(object $entity, EntityManagerInterface $em): bool
    {
        $metadata = $em->getClassMetadata($entity::class);

        return array_any(
            $metadata->getAssociationNames(),
            fn (string $field): bool => $metadata->isCollectionValuedAssociation($field)
                && $this->shouldDeferCollectionFieldMaterialization($entity, $field, $em),
        );
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
