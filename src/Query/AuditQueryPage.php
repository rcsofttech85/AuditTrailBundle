<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

/**
 * Materialized page of audit query results.
 */
final readonly class AuditQueryPage
{
    public function __construct(
        public AuditEntryCollection $entries,
        public ?string $nextCursor,
    ) {
    }

    public function first(): ?AuditEntry
    {
        return $this->entries->first();
    }

    public function isEmpty(): bool
    {
        return $this->entries->isEmpty();
    }
}
