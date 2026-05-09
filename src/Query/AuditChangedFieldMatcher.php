<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function array_any;
use function array_fill_keys;
use function array_filter;
use function array_values;
use function count;

final readonly class AuditChangedFieldMatcher
{
    /**
     * @param array<AuditLog> $logs
     * @param array<string>   $fields
     *
     * @return array<AuditLog>
     */
    public function filter(array $logs, array $fields): array
    {
        return array_values(array_filter(
            $logs,
            fn (AuditLog $log): bool => $this->matches($log, $fields),
        ));
    }

    /**
     * @param array<AuditLog> $logs
     * @param array<string>   $fields
     */
    public function countMatches(array $logs, array $fields): int
    {
        return count($this->filter($logs, $fields));
    }

    /**
     * @param array<string> $fields
     */
    public function matches(AuditLog $log, array $fields): bool
    {
        $changedFieldLookup = array_fill_keys($log->changedFields ?? [], true);

        return array_any(
            $fields,
            static fn (string $field): bool => isset($changedFieldLookup[$field]),
        );
    }
}
