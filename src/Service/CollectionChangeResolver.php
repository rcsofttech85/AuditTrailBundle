<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

use function array_values;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_callable;
use function is_iterable;
use function is_object;
use function is_string;
use function method_exists;

final readonly class CollectionChangeResolver
{
    public function __construct(
        private CollectionIdExtractor $collectionIdExtractor,
        private JoinTableCollectionIdLoader $joinTableCollectionIdLoader,
    ) {
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractCollectionChangesForOwner(
        object $owner,
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): array {
        $oldValues = [];
        $newValues = [];

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $this->mergeCollectionChange($collection, $owner, $em, $oldValues, $newValues);
        }

        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $this->mergeCollectionChange($collection, $owner, $em, $oldValues, $newValues);
        }

        $this->mergeOriginalCollectionChangesForOwner($owner, $em, $uow, $oldValues, $newValues);

        return [$oldValues, $newValues];
    }

    /**
     * @return array{field: string, old: array<int, int|string>, new: array<int, int|string>}|null
     */
    public function buildCollectionTransition(object $collection, EntityManagerInterface $em): ?array
    {
        if (!$this->isTrackableCollection($collection)) {
            return null;
        }

        $fieldName = $this->resolveCollectionFieldName($collection);
        if (!is_string($fieldName)) {
            return null;
        }

        $snapshot = $this->getCollectionSnapshot($collection);
        $oldIds = $this->collectionIdExtractor->extractFromIterable($snapshot, $em);
        $insertElements = array_values($this->getCollectionInsertDiff($collection));
        $deleteElements = array_values($this->getCollectionDeleteDiff($collection));

        if ($insertElements === [] && $deleteElements === []) {
            if ($oldIds === []) {
                return null;
            }

            return [
                'field' => $fieldName,
                'old' => $oldIds,
                'new' => [],
            ];
        }

        return [
            'field' => $fieldName,
            'old' => $oldIds,
            'new' => $this->computeNewIds($oldIds, $insertElements, $deleteElements, $em),
        ];
    }

    public function isTrackableCollection(object $collection): bool
    {
        if ($collection instanceof PersistentCollection) {
            return true;
        }

        return method_exists($collection, 'getOwner')
            && method_exists($collection, 'getInsertDiff')
            && method_exists($collection, 'getDeleteDiff')
            && method_exists($collection, 'getMapping')
            && method_exists($collection, 'getSnapshot');
    }

    public function getCollectionOwner(object $collection): ?object
    {
        if ($collection instanceof PersistentCollection) {
            return $collection->getOwner();
        }

        if (!$this->isTrackableCollection($collection)) {
            return null;
        }

        $ownerCallback = [$collection, 'getOwner'];
        if (!is_callable($ownerCallback)) {
            return null;
        }

        /** @var mixed $owner */
        $owner = $ownerCallback();

        return is_object($owner) ? $owner : null;
    }

    private function getCollectionMapping(object $collection): mixed
    {
        if ($collection instanceof PersistentCollection) {
            return $collection->getMapping();
        }

        /** @var callable(): mixed $mappingCallback */
        $mappingCallback = [$collection, 'getMapping'];

        return $mappingCallback();
    }

    /**
     * @return iterable<mixed>
     */
    private function getCollectionSnapshot(object $collection): iterable
    {
        if ($collection instanceof PersistentCollection) {
            return $collection->getSnapshot();
        }

        /** @var callable(): iterable<mixed> $snapshotCallback */
        $snapshotCallback = [$collection, 'getSnapshot'];

        return $snapshotCallback();
    }

    /**
     * @return array<int, object>
     */
    private function getCollectionInsertDiff(object $collection): array
    {
        if ($collection instanceof PersistentCollection) {
            return array_values($collection->getInsertDiff());
        }

        /** @var callable(): array<int, object> $insertDiffCallback */
        $insertDiffCallback = [$collection, 'getInsertDiff'];

        return $insertDiffCallback();
    }

    /**
     * @return array<int, object>
     */
    private function getCollectionDeleteDiff(object $collection): array
    {
        if ($collection instanceof PersistentCollection) {
            return array_values($collection->getDeleteDiff());
        }

        /** @var callable(): array<int, object> $deleteDiffCallback */
        $deleteDiffCallback = [$collection, 'getDeleteDiff'];

        return $deleteDiffCallback();
    }

    private function resolveCollectionFieldName(object $collection): ?string
    {
        $mapping = $this->getCollectionMapping($collection);

        return $this->extractFieldNameFromMapping($mapping);
    }

    private function extractFieldNameFromMapping(mixed $mapping): ?string
    {
        if (is_array($mapping)) {
            return $this->normalizeFieldName($mapping['fieldName'] ?? null);
        }

        if (!is_object($mapping)) {
            return null;
        }

        return $this->normalizeFieldName(get_object_vars($mapping)['fieldName'] ?? null);
    }

    private function normalizeFieldName(mixed $fieldName): ?string
    {
        return is_string($fieldName) ? $fieldName : null;
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function mergeCollectionChange(
        object $collection,
        object $owner,
        EntityManagerInterface $em,
        array &$oldValues,
        array &$newValues,
    ): void {
        if (!$this->isTrackableCollection($collection)) {
            return;
        }

        $collectionOwner = $this->getCollectionOwner($collection);
        if ($collectionOwner !== $owner) {
            return;
        }

        $transition = $this->buildCollectionTransition($collection, $em);
        if ($transition === null) {
            return;
        }

        $oldValues[$transition['field']] = $transition['old'];
        $newValues[$transition['field']] = $transition['new'];
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function mergeOriginalCollectionChangesForOwner(
        object $owner,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        array &$oldValues,
        array &$newValues,
    ): void {
        $metadata = $em->getClassMetadata($owner::class);
        $originalData = $uow->getOriginalEntityData($owner);

        foreach ($metadata->getAssociationNames() as $associationName) {
            if ($this->shouldSkipOriginalCollectionMerge($metadata, $associationName, $oldValues)) {
                continue;
            }

            $currentValue = $metadata->getFieldValue($owner, $associationName);
            if (!is_iterable($currentValue)) {
                continue;
            }

            $originalValue = $this->resolveOriginalCollectionValue($currentValue, $originalData, $associationName);
            if (!is_iterable($originalValue)) {
                continue;
            }

            $transition = $this->resolveOriginalCollectionTransition(
                $owner,
                $associationName,
                $currentValue,
                $originalValue,
                $em,
            );
            if ($transition === null) {
                continue;
            }

            $oldValues[$associationName] = $transition['old'];
            $newValues[$associationName] = $transition['new'];
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $oldValues
     */
    private function shouldSkipOriginalCollectionMerge(
        ClassMetadata $metadata,
        string $associationName,
        array $oldValues,
    ): bool {
        return isset($oldValues[$associationName]) || !$metadata->isCollectionValuedAssociation($associationName);
    }

    /**
     * @param array<string, mixed> $originalData
     */
    private function resolveOriginalCollectionValue(
        mixed $currentValue,
        array $originalData,
        string $associationName,
    ): mixed {
        if (is_object($currentValue) && method_exists($currentValue, 'getSnapshot')) {
            return $currentValue->getSnapshot();
        }

        return $originalData[$associationName] ?? null;
    }

    /**
     * @param iterable<mixed> $currentValue
     * @param iterable<mixed> $originalValue
     *
     * @return array{old: array<int, int|string>, new: array<int, int|string>}|null
     */
    private function resolveOriginalCollectionTransition(
        object $owner,
        string $associationName,
        iterable $currentValue,
        iterable $originalValue,
        EntityManagerInterface $em,
    ): ?array {
        $originalIds = $this->collectionIdExtractor->extractFromIterable($originalValue, $em);
        $currentIds = $this->collectionIdExtractor->extractFromIterable($currentValue, $em);
        if ($originalIds === $currentIds && $currentIds === []) {
            $originalIds = $this->joinTableCollectionIdLoader->loadOriginalCollectionIdsFromDatabase($owner, $associationName, $em);
        }

        if ($originalIds === $currentIds) {
            return null;
        }

        return ['old' => $originalIds, 'new' => $currentIds];
    }

    /**
     * @param array<int, int|string> $oldIds
     * @param array<int, object>     $insertDiff
     * @param array<int, object>     $deleteDiff
     *
     * @return array<int, int|string>
     */
    private function computeNewIds(
        array $oldIds,
        array $insertDiff,
        array $deleteDiff,
        EntityManagerInterface $em,
    ): array {
        $newIds = $oldIds;

        $insertedIds = $this->collectionIdExtractor->extractFromIterable($insertDiff, $em);
        foreach ($insertedIds as $id) {
            if (!in_array($id, $newIds, true)) {
                $newIds[] = $id;
            }
        }

        $deletedIds = $this->collectionIdExtractor->extractFromIterable($deleteDiff, $em);

        return array_values(array_filter($newIds, static fn ($id) => !in_array($id, $deletedIds, true)));
    }
}
