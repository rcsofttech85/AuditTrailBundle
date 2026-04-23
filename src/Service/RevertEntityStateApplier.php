<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;

final readonly class RevertEntityStateApplier
{
    public function __construct(
        private EntityManagerInterface $em,
        private SoftDeleteHandlerInterface $softDeleteHandler,
        private RevertCollectionAssociationSynchronizer $collectionSynchronizer,
    ) {
    }

    /**
     * @param array{
     *     fieldValues?: array<string, mixed>,
     *     restoreSoftDelete?: bool
     * } $revertData
     */
    public function apply(object $entity, array $revertData): void
    {
        $metadata = $this->em->getClassMetadata($entity::class);

        foreach ($revertData['fieldValues'] ?? [] as $field => $value) {
            $this->applyRevertFieldValue($metadata, $entity, $field, $value);
        }

        if (($revertData['restoreSoftDelete'] ?? false) === true) {
            $this->softDeleteHandler->restoreSoftDeleted($entity);
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function shouldSkipField(
        ClassMetadata $metadata,
        string $field,
        mixed $value,
        mixed $currentValue,
        bool $isComparableAssociation,
    ): bool {
        if ($metadata->isIdentifier($field) || (!$metadata->hasField($field) && !$metadata->hasAssociation($field))) {
            return true;
        }

        if ($isComparableAssociation) {
            return $this->collectionSynchronizer->collectionValuesAreEqual($currentValue, $value);
        }

        return false;
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
