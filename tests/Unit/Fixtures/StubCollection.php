<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

use ArrayIterator;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use IteratorAggregate;
use Rcsofttech\AuditTrailBundle\Service\TrackableCollectionInterface;

use function array_filter;
use function array_values;
use function spl_object_id;

/**
 * @implements IteratorAggregate<int, object>
 */
final class StubCollection implements IteratorAggregate, TrackableCollectionInterface
{
    /**
     * @param array<int, object> $insertDiff
     * @param array<int, object> $deleteDiff
     * @param array<int, object> $snapshot
     */
    public function __construct(
        private object $owner,
        private array $insertDiff,
        private array $deleteDiff,
        private AssociationMapping&ToManyAssociationMapping $mapping,
        private array $snapshot,
    ) {
    }

    public function getOwner(): object
    {
        return $this->owner;
    }

    /** @return array<int, object> */
    public function getInsertDiff(): array
    {
        return $this->insertDiff;
    }

    /** @return array<int, object> */
    public function getDeleteDiff(): array
    {
        return $this->deleteDiff;
    }

    public function getMapping(): AssociationMapping&ToManyAssociationMapping
    {
        return $this->mapping;
    }

    /** @return array<int, object> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * @return ArrayIterator<int, object>
     */
    public function getIterator(): ArrayIterator
    {
        $deletedObjectIds = [];
        foreach ($this->deleteDiff as $entity) {
            $deletedObjectIds[spl_object_id($entity)] = true;
        }

        $currentItems = array_values(array_filter(
            $this->snapshot,
            static fn (object $entity): bool => !isset($deletedObjectIds[spl_object_id($entity)]),
        ));

        return new ArrayIterator([...$currentItems, ...$this->insertDiff]);
    }
}
