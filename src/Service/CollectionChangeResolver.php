<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

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
        $oldIds = $this->collectionIdExtractor->extractFromIterable($collection->getSnapshot(), $em);
        $insertElements = array_values($this->getCollectionInsertDiff($collection));
        $deleteElements = array_values($this->getCollectionDeleteDiff($collection));

        if ($insertElements === [] && $deleteElements === []) {
            return $oldIds === []
                ? null
                : ['field' => $fieldName, 'old' => $oldIds, 'new' => []];
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
        if (!$this->isTrackableCollection($collection)) {
            return null;
        }

        return $collection->getOwner();
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

        foreach ($this->collectionIdExtractor->extractFromIterable($insertDiff, $em) as $id) {
            $lookupKey = $this->buildIdLookupKey($id);
            if (isset($newIdLookup[$lookupKey])) {
                continue;
            }

            $newIds[] = $id;
            $newIdLookup[$lookupKey] = true;
        }

        $deletedIdLookup = $this->buildIdLookup($this->collectionIdExtractor->extractFromIterable($deleteDiff, $em));
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
