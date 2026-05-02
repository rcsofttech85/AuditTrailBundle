<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;

use function is_string;
use function spl_object_id;
use function sprintf;

final readonly class RevertCollectionAssociationSynchronizer
{
    public function __construct(
        private EntityManagerInterface $em,
        private AssociationMutatorInvoker $mutatorInvoker,
    ) {
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function applyCollectionAssociationRevertData(
        ClassMetadata $metadata,
        object $entity,
        string $field,
        mixed $value,
    ): void {
        $currentValue = $metadata->getFieldValue($entity, $field);
        if (!$currentValue instanceof Collection || !$value instanceof Collection) {
            $metadata->setFieldValue($entity, $field, $value);

            return;
        }

        $mapping = $metadata->getAssociationMapping($field);
        $currentItems = $this->snapshotCollectionItems($currentValue);
        $currentLookup = $this->buildCollectionObjectLookup($currentItems);
        $incomingItems = $this->snapshotCollectionItems($value);
        $incomingLookup = $this->buildCollectionObjectLookup($incomingItems);

        foreach ($currentItems as $currentItem) {
            if (isset($incomingLookup[spl_object_id($currentItem)])) {
                continue;
            }

            $this->removeAssociationItem($metadata, $mapping, $entity, $field, $currentValue, $currentItem);
        }

        foreach ($incomingItems as $item) {
            if (isset($currentLookup[spl_object_id($item)])) {
                continue;
            }

            $this->addAssociationItem($metadata, $mapping, $entity, $field, $currentValue, $item);
        }
    }

    public function collectionValuesAreEqual(mixed $currentValue, mixed $newValue): bool
    {
        if (!$currentValue instanceof Collection || !$newValue instanceof Collection) {
            return false;
        }

        /** @var array<class-string, ClassMetadata<object>> $metadataByClass */
        $metadataByClass = [];

        return $this->normalizeCollectionIdentifiers($currentValue, $metadataByClass)
            === $this->normalizeCollectionIdentifiers($newValue, $metadataByClass);
    }

    /**
     * @param Collection<int|string, object>             $collection
     * @param array<class-string, ClassMetadata<object>> $metadataByClass
     *
     * @return list<string>
     */
    private function normalizeCollectionIdentifiers(Collection $collection, array &$metadataByClass): array
    {
        $identifiers = [];

        foreach ($collection as $item) {
            $identifiers[] = $this->normalizeEntityIdentifier($item, $metadataByClass);
        }

        sort($identifiers);

        return $identifiers;
    }

    /**
     * @param array<class-string, ClassMetadata<object>> $metadataByClass
     */
    private function normalizeEntityIdentifier(object $entity, array &$metadataByClass): string
    {
        $metadata = $metadataByClass[$entity::class] ??= $this->em->getClassMetadata($entity::class);
        $identifierValues = $metadata->getIdentifierValues($entity);

        if ($identifierValues === []) {
            return (string) spl_object_id($entity);
        }

        $normalized = [];
        foreach ($identifierValues as $field => $value) {
            $normalized[] = sprintf('%s=%s', $field, (string) $value);
        }

        sort($normalized);

        return implode('|', $normalized);
    }

    /**
     * @param ClassMetadata<object>          $metadata
     * @param Collection<int|string, object> $currentValue
     */
    private function addAssociationItem(
        ClassMetadata $metadata,
        AssociationMapping $mapping,
        object $entity,
        string $field,
        Collection $currentValue,
        object $item,
    ): void {
        if ($this->mutatorInvoker->invokeCollectionMutator($entity, 'add', $item)) {
            return;
        }

        $currentValue->add($item);
        $this->synchronizeCounterpartAssociation($metadata, $mapping, $entity, $field, $item, true);
    }

    /**
     * @param ClassMetadata<object>          $metadata
     * @param Collection<int|string, object> $currentValue
     */
    private function removeAssociationItem(
        ClassMetadata $metadata,
        AssociationMapping $mapping,
        object $entity,
        string $field,
        Collection $currentValue,
        object $item,
    ): void {
        if ($this->mutatorInvoker->invokeCollectionMutator($entity, 'remove', $item)) {
            return;
        }

        $currentValue->removeElement($item);
        $this->synchronizeCounterpartAssociation($metadata, $mapping, $entity, $field, $item, false);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function synchronizeCounterpartAssociation(
        ClassMetadata $metadata,
        AssociationMapping $mapping,
        object $entity,
        string $field,
        object $item,
        bool $adding,
    ): void {
        $counterpartField = $this->resolveCounterpartField($mapping);
        if (!is_string($counterpartField)) {
            return;
        }

        if ($this->mutatorInvoker->invokeCounterpartMutator($entity, $item, $adding)) {
            return;
        }

        $targetMetadata = $this->em->getClassMetadata($item::class);
        if (!$this->targetMetadataContainsField($targetMetadata, $counterpartField)) {
            return;
        }

        if ($targetMetadata->isCollectionValuedAssociation($counterpartField)) {
            $this->synchronizeCollectionCounterpart($targetMetadata, $item, $counterpartField, $entity, $adding);

            return;
        }

        $this->synchronizeSingleValuedCounterpart($targetMetadata, $item, $counterpartField, $entity, $field, $adding);
    }

    private function resolveCounterpartField(AssociationMapping $mapping): ?string
    {
        return match (true) {
            $mapping instanceof OwningSideMapping => $mapping->inversedBy,
            $mapping instanceof InverseSideMapping => $mapping->mappedBy,
            default => null,
        };
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function targetMetadataContainsField(ClassMetadata $targetMetadata, string $field): bool
    {
        return $targetMetadata->hasAssociation($field) || $targetMetadata->hasField($field);
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function synchronizeCollectionCounterpart(
        ClassMetadata $targetMetadata,
        object $item,
        string $counterpartField,
        object $entity,
        bool $adding,
    ): void {
        $counterpartValue = $targetMetadata->getFieldValue($item, $counterpartField);
        if (!$counterpartValue instanceof Collection) {
            return;
        }

        if ($adding) {
            if (!$counterpartValue->contains($entity)) {
                $counterpartValue->add($entity);
            }

            return;
        }

        $counterpartValue->removeElement($entity);
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function synchronizeSingleValuedCounterpart(
        ClassMetadata $targetMetadata,
        object $item,
        string $counterpartField,
        object $entity,
        string $field,
        bool $adding,
    ): void {
        $counterpartValue = $targetMetadata->getFieldValue($item, $counterpartField);
        if ($adding || $counterpartValue === $entity) {
            $targetMetadata->setFieldValue($item, $counterpartField, $adding ? $entity : null);
        }
    }

    /**
     * @param Collection<int|string, object> $collection
     *
     * @return list<object>
     */
    private function snapshotCollectionItems(Collection $collection): array
    {
        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<object> $items
     *
     * @return array<int, true>
     */
    private function buildCollectionObjectLookup(array $items): array
    {
        $lookup = [];
        foreach ($items as $item) {
            $lookup[spl_object_id($item)] = true;
        }

        return $lookup;
    }
}
