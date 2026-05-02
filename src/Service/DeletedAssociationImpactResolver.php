<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;

use function spl_object_id;

final class DeletedAssociationImpactResolver
{
    /**
     * @param list<AssociationImpact> $impacts
     *
     * @return array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>>
     */
    public function indexByOwner(array $impacts): array
    {
        $indexed = [];

        foreach ($impacts as $impact) {
            $indexed[spl_object_id($impact->entity)][$impact->field] = [
                'old' => $impact->old,
                'new' => $impact->new,
            ];
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>> $indexedImpacts
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractChangesForOwner(object $owner, array $indexedImpacts): array
    {
        $ownerImpacts = $indexedImpacts[spl_object_id($owner)] ?? null;
        if ($ownerImpacts === null) {
            return [[], []];
        }

        $oldValues = [];
        $newValues = [];

        foreach ($ownerImpacts as $field => $impact) {
            $oldValues[$field] = $impact['old'];
            $newValues[$field] = $impact['new'];
        }

        return [$oldValues, $newValues];
    }
}
