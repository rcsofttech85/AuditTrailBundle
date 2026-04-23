<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;

final readonly class RevertPlanBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private RevertValueDenormalizer $denormalizer,
        private ValueSerializerInterface $serializer,
        private RevertCollectionAssociationSynchronizer $collectionSynchronizer,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{changes: array<string, mixed>, previousValues: array<string, mixed>, fieldValues: array<string, mixed>}
     */
    public function build(object $entity, array $values, bool $dryRun): array
    {
        $metadata = $this->em->getClassMetadata($entity::class);
        $appliedChanges = [];
        $previousValues = [];

        foreach ($values as $field => $value) {
            $denormalizedValue = $this->denormalizer->denormalize($metadata, $field, $value, $dryRun);
            $currentValue = $metadata->getFieldValue($entity, $field);

            if ($this->shouldSkipField($metadata, $field, $denormalizedValue, $currentValue)) {
                continue;
            }

            $appliedChanges[$field] = $denormalizedValue;
            $previousValues[$field] = $this->serializer->serialize($currentValue);
        }

        return [
            'changes' => $appliedChanges,
            'previousValues' => $previousValues,
            'fieldValues' => $appliedChanges,
        ];
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function shouldSkipField(
        ClassMetadata $metadata,
        string $field,
        mixed $value,
        mixed $currentValue,
    ): bool {
        if ($metadata->isIdentifier($field) || (!$metadata->hasField($field) && !$metadata->hasAssociation($field))) {
            return true;
        }

        if ($metadata->hasAssociation($field) && $metadata->isCollectionValuedAssociation($field)) {
            return $this->collectionSynchronizer->collectionValuesAreEqual($currentValue, $value);
        }

        return $this->denormalizer->valuesAreEqual($currentValue, $value);
    }
}
