<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use function is_int;

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
        $lookup = $this->buildIdLookup($left);

        foreach ($right as $id) {
            $lookupKey = $this->buildIdLookupKey($id);
            if (isset($lookup[$lookupKey])) {
                continue;
            }

            $merged[] = $id;
            $lookup[$lookupKey] = true;
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
        $toRemoveLookup = $this->buildIdLookup($toRemove);
        $filtered = [];

        foreach ($source as $id) {
            if (isset($toRemoveLookup[$this->buildIdLookupKey($id)])) {
                continue;
            }

            $filtered[] = $id;
        }

        return $filtered;
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
