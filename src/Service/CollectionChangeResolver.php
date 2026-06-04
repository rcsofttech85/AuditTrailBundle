<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\TrackableCollectionInterface;

use function array_values;
use function is_int;
use function spl_object_id;

/**
 * @phpstan-type TrackableCollection PersistentCollection<int, object>|TrackableCollectionInterface
 */
final readonly class CollectionChangeResolver
{
    public function __construct(
        private CollectionIdExtractor $collectionIdExtractor,
        private CollectionChangeIndexBuilder $indexBuilder,
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
        return $this->extractIndexedCollectionChangesForOwner(
            $owner,
            $this->extractCollectionChangesIndexedByOwner($em, $uow),
        );
    }

    /**
     * @return array<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    public function extractCollectionChangesIndexedByOwner(
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): array {
        return $this->indexBuilder->build($em, $uow, $this);
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
        $insertElements = array_values($this->getCollectionInsertDiff($collection));
        $deleteElements = array_values($this->getCollectionDeleteDiff($collection));
        $oldIds = $this->resolveOriginalCollectionIds(
            $collection,
            $fieldName,
            $insertElements !== [] || $deleteElements !== [],
            $em,
        );

        if ($insertElements === [] && $deleteElements === []) {
            $currentIds = [];
            $oldIds = $this->resolveOriginalCollectionIds($collection, $fieldName, true, $em);

            return $oldIds === []
                ? null
                : ['field' => $fieldName, 'old' => $oldIds, 'new' => $currentIds];
        }

        return [
            'field' => $fieldName,
            'old' => $oldIds,
            'new' => $this->normalizeUniqueIds($this->resolveCurrentCollectionIds($collection, $em, $oldIds)),
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
        if (!$this->isTrackableCollection($collection)) {
            return null;
        }

        return $collection->getOwner();
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return array<int, int|string>
     */
    public function extractCollectionIdsFromIterable(iterable $items, EntityManagerInterface $em): array
    {
        return $this->collectionIdExtractor->extractFromIterable($items, $em);
    }

    public function collectionContainsPendingIds(mixed $items, EntityManagerInterface $em): bool
    {
        return $this->collectionIdExtractor->hasPendingIds($items, $em);
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
    private function resolveCollectionFieldName(
        PersistentCollection|TrackableCollectionInterface $collection,
    ): string {
        /** @var AssociationMapping&ToManyAssociationMapping $mapping */
        $mapping = $collection->getMapping();

        return $mapping->fieldName;
    }

    /**
     * @param TrackableCollection $collection
     *
     * @return array<int, int|string>
     */
    private function resolveOriginalCollectionIds(
        PersistentCollection|TrackableCollectionInterface $collection,
        string $fieldName,
        bool $allowDatabaseFallback,
        EntityManagerInterface $em,
    ): array {
        $oldIds = $this->collectionIdExtractor->extractFromIterable($collection->getSnapshot(), $em);
        if ($oldIds !== [] || !$allowDatabaseFallback) {
            return $oldIds;
        }

        $owner = $collection->getOwner();
        if ($owner === null) {
            return [];
        }

        return $this->joinTableCollectionIdLoader->loadOriginalCollectionIdsFromDatabase(
            $owner,
            $fieldName,
            $em,
        );
    }

    /**
     * @param TrackableCollection    $collection
     * @param array<int, int|string> $baseIds
     *
     * @return array<int, int|string>
     */
    private function resolveCurrentCollectionIds(
        PersistentCollection|TrackableCollectionInterface $collection,
        EntityManagerInterface $em,
        array $baseIds,
    ): array {
        if ($collection instanceof PersistentCollection) {
            if (!$collection->isInitialized()) {
                return $this->applyCollectionDiffsToBaseIds(
                    $baseIds,
                    $this->extractCollectionIdsFromIterable($this->getCollectionDeleteDiff($collection), $em),
                    $this->extractCollectionIdsFromIterable($this->getCollectionInsertDiff($collection), $em),
                );
            }

            return $this->collectionIdExtractor->extractFromIterable($collection, $em);
        }

        return $this->applyCollectionDiffsToBaseIds(
            $this->extractCollectionIdsFromIterable($collection->getSnapshot(), $em),
            $this->extractCollectionIdsFromIterable($this->getCollectionDeleteDiff($collection), $em),
            $this->extractCollectionIdsFromIterable($this->getCollectionInsertDiff($collection), $em),
        );
    }

    /**
     * @param array<int, int|string> $baseIds
     * @param array<int, int|string> $deleteDiffIds
     * @param array<int, int|string> $insertDiffIds
     *
     * @return array<int, int|string>
     */
    private function applyCollectionDiffsToBaseIds(
        array $baseIds,
        array $deleteDiffIds,
        array $insertDiffIds,
    ): array {
        $deletedIds = [];
        foreach ($deleteDiffIds as $id) {
            $deletedIds[$this->buildComparableIdLookupKey($id)] = true;
        }

        $currentIds = [];
        $currentLookup = [];
        foreach ($baseIds as $id) {
            $lookupKey = $this->buildComparableIdLookupKey($id);
            if (isset($deletedIds[$lookupKey])) {
                continue;
            }

            $currentIds[] = $id;
            $currentLookup[$lookupKey] = true;
        }

        foreach ($insertDiffIds as $id) {
            $lookupKey = $this->buildComparableIdLookupKey($id);
            if (isset($currentLookup[$lookupKey])) {
                continue;
            }

            $currentIds[] = $id;
            $currentLookup[$lookupKey] = true;
        }

        return $currentIds;
    }

    /**
     * @param array<int, int|string> $ids
     *
     * @return array<int, int|string>
     */
    private function normalizeUniqueIds(array $ids): array
    {
        $normalized = [];
        $lookup = [];

        foreach ($ids as $id) {
            $lookupKey = $this->buildIdLookupKey($id);
            if (isset($lookup[$lookupKey])) {
                continue;
            }

            $normalized[] = $id;
            $lookup[$lookupKey] = true;
        }

        return $normalized;
    }

    private function buildIdLookupKey(int|string $id): string
    {
        return is_int($id) ? 'i:'.$id : 's:'.$id;
    }

    private function buildComparableIdLookupKey(int|string $id): string
    {
        return (string) $id;
    }
}
