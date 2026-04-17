<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

use function array_values;
use function is_int;
use function is_iterable;
use function spl_object_id;

/**
 * @phpstan-type TrackableCollection PersistentCollection<int, object>|TrackableCollectionInterface
 */
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
        $indexedChanges = $this->extractCollectionChangesIndexedByOwner($em, $uow);

        return $this->extractIndexedCollectionChangesForOwner($owner, $indexedChanges);
    }

    /**
     * @return array<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    public function extractCollectionChangesIndexedByOwner(
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): array {
        $indexedChanges = [];
        /** @var array<class-string, ClassMetadata<object>> $metadataCache */
        $metadataCache = [];

        $this->mergeScheduledCollectionChangesIntoIndex($uow->getScheduledCollectionUpdates(), $em, $indexedChanges);
        $this->mergeScheduledCollectionChangesIntoIndex($uow->getScheduledCollectionDeletions(), $em, $indexedChanges);
        $this->mergeOwnerCollectionChangesIntoIndex($uow, $em, $metadataCache, $indexedChanges);

        return $indexedChanges;
    }

    /**
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractIndexedCollectionChangesForOwner(object $owner, array $indexedChanges): array
    {
        $ownerChanges = $indexedChanges[spl_object_id($owner)] ?? null;
        if ($ownerChanges === null) {
            return [[], []];
        }

        return [$ownerChanges['old'], $ownerChanges['new']];
    }

    /**
     * @param TrackableCollection $collection
     *
     * @return array{field: string, old: array<int, int|string>, new: array<int, int|string>}|null
     */
    public function buildCollectionTransition(
        PersistentCollection|TrackableCollectionInterface $collection,
        EntityManagerInterface $em,
    ): ?array {
        $fieldName = $this->resolveCollectionFieldName($collection);
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

    /**
     * @phpstan-assert-if-true TrackableCollection $collection
     */
    public function isTrackableCollection(object $collection): bool
    {
        return $collection instanceof PersistentCollection || $collection instanceof TrackableCollectionInterface;
    }

    public function getCollectionOwner(object $collection): ?object
    {
        return $this->normalizeTrackableCollection($collection)?->getOwner();
    }

    /**
     * @return TrackableCollection|null
     */
    private function normalizeTrackableCollection(
        object $collection,
    ): PersistentCollection|TrackableCollectionInterface|null {
        if (!$this->isTrackableCollection($collection)) {
            return null;
        }

        return $collection;
    }

    /**
     * @param TrackableCollection $collection
     */
    private function getCollectionMapping(
        PersistentCollection|TrackableCollectionInterface $collection,
    ): AssociationMapping&ToManyAssociationMapping {
        return $collection->getMapping();
    }

    /**
     * @param TrackableCollection $collection
     *
     * @return iterable<mixed>
     */
    private function getCollectionSnapshot(PersistentCollection|TrackableCollectionInterface $collection): iterable
    {
        return $collection->getSnapshot();
    }

    /**
     * @param TrackableCollection $collection
     *
     * @return array<int, object>
     */
    private function getCollectionInsertDiff(PersistentCollection|TrackableCollectionInterface $collection): array
    {
        if ($collection instanceof PersistentCollection) {
            return array_values($collection->getInsertDiff());
        }

        return $collection->getInsertDiff();
    }

    /**
     * @param TrackableCollection $collection
     *
     * @return array<int, object>
     */
    private function getCollectionDeleteDiff(PersistentCollection|TrackableCollectionInterface $collection): array
    {
        if ($collection instanceof PersistentCollection) {
            return array_values($collection->getDeleteDiff());
        }

        return $collection->getDeleteDiff();
    }

    /**
     * @param TrackableCollection $collection
     */
    private function resolveCollectionFieldName(PersistentCollection|TrackableCollectionInterface $collection): string
    {
        return $this->getCollectionMapping($collection)->fieldName;
    }

    /**
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     */
    private function mergeCollectionChangeIntoIndex(
        object $collection,
        EntityManagerInterface $em,
        array &$indexedChanges,
    ): void {
        $trackableCollection = $this->normalizeTrackableCollection($collection);
        if ($trackableCollection === null) {
            return;
        }

        $collectionOwner = $trackableCollection->getOwner();
        if ($collectionOwner === null) {
            return;
        }

        $transition = $this->buildCollectionTransition($trackableCollection, $em);
        if ($transition === null) {
            return;
        }

        $ownerId = spl_object_id($collectionOwner);
        $indexedChanges[$ownerId] ??= [
            'old' => [],
            'new' => [],
        ];
        $indexedChanges[$ownerId]['old'][$transition['field']] = $transition['old'];
        $indexedChanges[$ownerId]['new'][$transition['field']] = $transition['new'];
    }

    /**
     * @param iterable<object>                                                        $collections
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     */
    private function mergeScheduledCollectionChangesIntoIndex(
        iterable $collections,
        EntityManagerInterface $em,
        array &$indexedChanges,
    ): void {
        foreach ($collections as $collection) {
            $this->mergeCollectionChangeIntoIndex($collection, $em, $indexedChanges);
        }
    }

    /**
     * @param array<class-string, ClassMetadata<object>>                              $metadataCache
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     */
    private function mergeOwnerCollectionChangesIntoIndex(
        UnitOfWork $uow,
        EntityManagerInterface $em,
        array &$metadataCache,
        array &$indexedChanges,
    ): void {
        foreach ($uow->getScheduledEntityUpdates() as $owner) {
            $ownerChanges = $this->resolveOwnerCollectionChanges($owner, $uow, $em, $metadataCache, $indexedChanges);
            if ($ownerChanges === null) {
                continue;
            }

            $indexedChanges[spl_object_id($owner)] = $ownerChanges;
        }
    }

    /**
     * @param array<class-string, ClassMetadata<object>>                              $metadataCache
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    private function resolveOwnerCollectionChanges(
        object $owner,
        UnitOfWork $uow,
        EntityManagerInterface $em,
        array &$metadataCache,
        array $indexedChanges,
    ): ?array {
        $ownerId = spl_object_id($owner);
        /** @var array<string, mixed> $oldValues */
        $oldValues = $indexedChanges[$ownerId]['old'] ?? [];
        /** @var array<string, mixed> $newValues */
        $newValues = $indexedChanges[$ownerId]['new'] ?? [];

        $this->mergeOriginalCollectionChangesForOwner($owner, $em, $uow, $metadataCache, $oldValues, $newValues);

        if ($oldValues === [] && $newValues === []) {
            return null;
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }

    /**
     * @param array<class-string, ClassMetadata<object>> $metadataCache
     * @param array<string, mixed>                       $oldValues
     * @param array<string, mixed>                       $newValues
     */
    private function mergeOriginalCollectionChangesForOwner(
        object $owner,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        array &$metadataCache,
        array &$oldValues,
        array &$newValues,
    ): void {
        $metadata = $metadataCache[$owner::class] ??= $em->getClassMetadata($owner::class);
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
        if ($currentValue instanceof PersistentCollection || $currentValue instanceof TrackableCollectionInterface) {
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
        $newIdLookup = $this->buildIdLookup($newIds);

        $insertedIds = $this->collectionIdExtractor->extractFromIterable($insertDiff, $em);
        foreach ($insertedIds as $id) {
            $lookupKey = $this->buildIdLookupKey($id);
            if (isset($newIdLookup[$lookupKey])) {
                continue;
            }

            $newIds[] = $id;
            $newIdLookup[$lookupKey] = true;
        }

        $deletedIds = $this->collectionIdExtractor->extractFromIterable($deleteDiff, $em);
        $deletedIdLookup = $this->buildIdLookup($deletedIds);
        $filteredIds = [];

        foreach ($newIds as $id) {
            if (isset($deletedIdLookup[$this->buildIdLookupKey($id)])) {
                continue;
            }

            $filteredIds[] = $id;
        }

        return $filteredIds;
    }

    /**
     * @param array<int, int|string> $ids
     *
     * @return array<string, true>
     */
    private function buildIdLookup(array $ids): array
    {
        $lookup = [];

        foreach ($ids as $id) {
            $lookup[$this->buildIdLookupKey($id)] = true;
        }

        return $lookup;
    }

    private function buildIdLookupKey(int|string $id): string
    {
        return is_int($id) ? 'i:'.$id : 's:'.$id;
    }
}
