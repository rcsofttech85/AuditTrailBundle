<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReaderInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;

/**
 * Main service for programmatic audit log retrieval.
 *
 * Provides a fluent API for querying audit logs with rich result objects.
 *
 * Usage:
 *     $reader->forEntity(User::class, '123')
 *            ->since(new \DateTimeImmutable('-30 days'))
 *            ->updates()
 *            ->getResults();
 */
readonly class AuditReader implements AuditReaderInterface
{
    public function __construct(
        private AuditLogRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create a new query builder.
     */
    public function createQuery(): AuditQuery
    {
        return new AuditQuery($this->repository);
    }

    /**
     * Create a query pre-filtered for a specific entity class and optional ID.
     */
    public function forEntity(string $entityClass, ?string $entityId = null): AuditQuery
    {
        return $this->createQuery()->entity($entityClass, $entityId);
    }

    /**
     * Create a query pre-filtered for a specific user.
     */
    public function byUser(string $userId): AuditQuery
    {
        return $this->createQuery()->user($userId);
    }

    /**
     * Create a query pre-filtered for a specific transaction.
     */
    public function byTransaction(string $transactionHash): AuditQuery
    {
        return $this->createQuery()->transaction($transactionHash);
    }

    /**
     * Get the complete audit history for a specific entity instance.
     *
     * This method extracts the class and ID from the entity object automatically.
     */
    public function getHistoryFor(object $entity): AuditEntryCollection
    {
        $entityId = $this->extractEntityId($entity);

        if ($entityId === null) {
            return new AuditEntryCollection([]);
        }

        return $this->forEntity($entity::class, $entityId)->getResults();
    }

    /**
     * Get audit entries for a specific entity grouped by transaction.
     *
     * @return array<string, list<AuditEntry>>
     */
    public function getTimelineFor(object $entity): array
    {
        $history = $this->getHistoryFor($entity);
        $grouped = [];

        foreach ($history as $entry) {
            $txHash = $entry->getTransactionHash() ?? 'unknown';
            $grouped[$txHash] ??= [];
            $grouped[$txHash][] = $entry;
        }

        return $grouped;
    }

    /**
     * Get the latest audit entry for an entity.
     */
    public function getLatestFor(object $entity): ?AuditEntry
    {
        $entityId = $this->extractEntityId($entity);

        if ($entityId === null) {
            return null;
        }

        return $this->forEntity($entity::class, $entityId)->getFirstResult();
    }

    /**
     * Check if an entity has any audit history.
     */
    public function hasHistoryFor(object $entity): bool
    {
        $entityId = $this->extractEntityId($entity);

        if ($entityId === null) {
            return false;
        }

        return $this->forEntity($entity::class, $entityId)->exists();
    }

    /**
     * Extract entity ID as string.
     */
    private function extractEntityId(object $entity): ?string
    {
        $id = EntityIdResolver::resolveFromEntity($entity, $this->entityManager);

        return $id === EntityIdResolver::PENDING_ID ? null : $id;
    }
}
