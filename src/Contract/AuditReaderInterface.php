<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Query\AuditQuery;

/**
 * Interface for programmatic audit log retrieval.
 *
 * Provides a fluent API for querying audit logs with filtering,
 * pagination, and rich result objects.
 */
interface AuditReaderInterface
{
    /**
     * Create a new query builder.
     */
    public function createQuery(): AuditQuery;

    /**
     * Create a query pre-filtered for a specific entity class and optional ID.
     */
    public function forEntity(string $entityClass, ?string $entityId = null): AuditQuery;

    /**
     * Create a query pre-filtered for a specific user.
     */
    public function byUser(int $userId): AuditQuery;

    /**
     * Create a query pre-filtered for a specific transaction.
     */
    public function byTransaction(string $transactionHash): AuditQuery;

    /**
     * Get the complete audit history for a specific entity instance.
     */
    public function getHistoryFor(object $entity): AuditEntryCollection;
}
