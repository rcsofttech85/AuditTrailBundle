<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

class StubCollection
{
    /**
     * @param array<int, object>   $insertDiff
     * @param array<int, object>   $deleteDiff
     * @param array<string, mixed> $mapping
     * @param array<int, object>   $snapshot
     */
    public function __construct(
        private object $owner,
        private array $insertDiff,
        private array $deleteDiff,
        private array $mapping,
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

    /** @return array<string, mixed> */
    public function getMapping(): array
    {
        return $this->mapping;
    }

    /** @return array<int, object> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }
}
