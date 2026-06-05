<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\TrackableCollectionInterface;

use function is_iterable;
use function is_object;

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
     * Read ids through a separate Doctrine criteria result without hydrating
     * the owning PersistentCollection.
     *
     * Use this only when the database state is the intended source of truth for
     * the collection, and the collection has no pending in-memory diffs that
     * must be folded into the result.
     *
     * @param PersistentCollection<int|string, mixed> $collection
     *
     * @return array<int, string>
     */
    public function extractFromPersistentCollectionCriteria(PersistentCollection $collection, EntityManagerInterface $em): array
    {
        return $this->extractFromIterable($collection->matching(Criteria::create()), $em);
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
