<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\TrackableCollectionInterface;

use function is_iterable;
use function is_object;
use function spl_object_id;

final readonly class CollectionIdExtractor
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
    ) {
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return array<int, string>
     */
    public function extractFromIterable(iterable $items, EntityManagerInterface $em): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            $id = $this->idResolver->resolveFromEntity($item, $em);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Resolve the ids a collection currently holds without initializing it.
     *
     * Iterating a lazy {@see PersistentCollection} (e.g. through
     * {@see self::extractFromIterable()}) forces Doctrine to load it from the
     * database. Doing so while resolving audit changes during flush leaves the
     * collection initialized and frozen for the rest of the request, which
     * corrupts later reads such as an EXTRA_LAZY count(). The already-known
     * elements live in the snapshot and the pending insert/delete diffs, so
     * resolve the ids from those instead of touching the live collection.
     *
     * @param PersistentCollection<int, object> $collection
     *
     * @return array<int, string>
     */
    public function extractIdsWithoutInitializing(PersistentCollection $collection, EntityManagerInterface $em): array
    {
        $deleted = [];
        foreach ($collection->getDeleteDiff() as $entity) {
            if (is_object($entity)) {
                $deleted[spl_object_id($entity)] = true;
            }
        }

        $kept = [];
        foreach ($collection->getSnapshot() as $entity) {
            if (is_object($entity) && isset($deleted[spl_object_id($entity)])) {
                continue;
            }

            $kept[] = $entity;
        }

        return $this->extractFromIterable([...$kept, ...$collection->getInsertDiff()], $em);
    }

    public function hasPendingIds(mixed $items, EntityManagerInterface $em): bool
    {
        if (!is_iterable($items)) {
            return false;
        }

        if ($items instanceof PersistentCollection && !$items->isInitialized()) {
            if (!$items->isDirty()) {
                return false;
            }

            return $this->iterableContainsUnresolvedIds($items->getInsertDiff(), $em);
        }

        if ($items instanceof TrackableCollectionInterface) {
            return $this->iterableContainsUnresolvedIds($items->getInsertDiff(), $em);
        }

        return $this->iterableContainsUnresolvedIds($items, $em);
    }

    /**
     * @param iterable<mixed> $items
     */
    private function iterableContainsUnresolvedIds(iterable $items, EntityManagerInterface $em): bool
    {
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            if ($this->idResolver->resolveFromEntity($item, $em) === null) {
                return true;
            }
        }

        return false;
    }
}
