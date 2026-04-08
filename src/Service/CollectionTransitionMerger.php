<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use function array_filter;
use function array_values;
use function in_array;

final readonly class CollectionTransitionMerger
{
    /**
     * @param array<int, int|string> $existingOld
     * @param array<int, int|string> $existingNew
     * @param array<int, int|string> $incomingOld
     * @param array<int, int|string> $incomingNew
     */
    public function mergeSingleFieldTransition(
        array &$existingOld,
        array &$existingNew,
        array $incomingOld,
        array $incomingNew,
    ): void {
        $combinedOld = $this->mergeUniqueIds($existingOld, $incomingOld);
        $deletedIds = $this->mergeUniqueIds(
            $this->diffIds($existingOld, $existingNew),
            $this->diffIds($incomingOld, $incomingNew),
        );
        $addedIds = $this->mergeUniqueIds(
            $this->diffIds($existingNew, $existingOld),
            $this->diffIds($incomingNew, $incomingOld),
        );

        $baseNew = $this->diffIds($combinedOld, $deletedIds);
        $existingOld = $combinedOld;
        $existingNew = $this->mergeUniqueIds($baseNew, $addedIds);
    }

    /**
     * @param array<int, int|string> $left
     * @param array<int, int|string> $right
     *
     * @return array<int, int|string>
     */
    private function mergeUniqueIds(array $left, array $right): array
    {
        $merged = $left;

        foreach ($right as $id) {
            if (!in_array($id, $merged, true)) {
                $merged[] = $id;
            }
        }

        return $merged;
    }

    /**
     * @param array<int, int|string> $source
     * @param array<int, int|string> $toRemove
     *
     * @return array<int, int|string>
     */
    private function diffIds(array $source, array $toRemove): array
    {
        return array_values(array_filter($source, static fn ($id) => !in_array($id, $toRemove, true)));
    }
}
