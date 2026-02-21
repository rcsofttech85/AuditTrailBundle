<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeImmutable;
use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function array_key_exists;
use function count;
use function in_array;

use const PHP_INT_MAX;

/**
 * Fluent, immutable query builder for audit logs.
 *
 * Each method returns a new instance, preserving immutability.
 * Execute the query with getResults(), count(), or getFirstResult().
 *
 * Uses keyset (cursor) pagination for efficient large dataset traversal.
 */
readonly class AuditQuery
{
    private const int DEFAULT_LIMIT = 30;

    /**
     * @param array<string> $actions
     * @param array<string> $changedFields
     */
    public function __construct(
        private AuditLogRepositoryInterface $repository,
        private ?string $entityClass = null,
        private ?string $entityId = null,
        private array $actions = [],
        private ?string $userId = null,
        private ?string $transactionHash = null,
        private ?DateTimeInterface $since = null,
        private ?DateTimeInterface $until = null,
        private array $changedFields = [],
        private int $limit = self::DEFAULT_LIMIT,
        private ?string $afterId = null,
        private ?string $beforeId = null,
    ) {
    }

    /**
     * Filter by entity class and optional ID.
     */
    public function entity(string $class, ?string $id = null): self
    {
        return $this->with(['entityClass' => $class, 'entityId' => $id]);
    }

    /**
     * Filter by entity ID (requires entity class to be set).
     */
    public function entityId(string $id): self
    {
        return $this->with(['entityId' => $id]);
    }

    /**
     * Filter by one or more action types.
     */
    public function action(string ...$actions): self
    {
        return $this->with(['actions' => $actions]);
    }

    /**
     * Filter for create actions only.
     */
    public function creates(): self
    {
        return $this->action('create');
    }

    /**
     * Filter for update actions only.
     */
    public function updates(): self
    {
        return $this->action('update');
    }

    /**
     * Filter for delete actions only.
     */
    public function deletes(): self
    {
        return $this->action('delete', 'soft_delete');
    }

    /**
     * Filter by user ID.
     */
    public function user(string $userId): self
    {
        return $this->with(['userId' => $userId]);
    }

    /**
     * Filter by transaction hash.
     */
    public function transaction(string $hash): self
    {
        return $this->with(['transactionHash' => $hash]);
    }

    /**
     * Filter for logs created on or after the given date.
     */
    public function since(DateTimeInterface $from): self
    {
        return $this->with(['since' => $from]);
    }

    /**
     * Filter for logs created on or before the given date.
     */
    public function until(DateTimeInterface $to): self
    {
        return $this->with(['until' => $to]);
    }

    /**
     * Filter for logs within a date range.
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return $this->since($from)->until($to);
    }

    /**
     * Filter for logs that changed specific fields.
     */
    public function changedField(string ...$fields): self
    {
        return $this->with(['changedFields' => $fields]);
    }

    /**
     * Limit the number of results.
     */
    public function limit(int $limit): self
    {
        return $this->with(['limit' => $limit]);
    }

    /**
     * Keyset pagination: Get results after a specific audit log ID (UUID string).
     */
    public function after(string $id): self
    {
        return $this->with(['afterId' => $id, 'beforeId' => null]);
    }

    /**
     * Keyset pagination: Get results before a specific audit log ID (UUID string).
     */
    public function before(string $id): self
    {
        return $this->with(['afterId' => null, 'beforeId' => $id]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function with(array $params): self
    {
        $state = [
            'repository' => $this->repository,
            'entityClass' => $this->entityClass,
            'entityId' => $this->entityId,
            'actions' => $this->actions,
            'userId' => $this->userId,
            'transactionHash' => $this->transactionHash,
            'since' => $this->since,
            'until' => $this->until,
            'changedFields' => $this->changedFields,
            'limit' => $this->limit,
            'afterId' => $this->afterId,
            'beforeId' => $this->beforeId,
        ];

        foreach ($params as $key => $value) {
            if (array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }

        /** @var array{
         *     repository: AuditLogRepositoryInterface,
         *     entityClass: ?string,
         *
         *     entityId: ?string,
         *     actions: array<string>,
         *     userId: ?string,
         *     transactionHash: ?string,
         *     since: ?DateTimeInterface,
         *     until: ?DateTimeInterface,
         *     changedFields: array<string>,
         *     limit: int,
         *     afterId: ?string,
         *     beforeId: ?string
         * } $state */
        return new self(...$state);
    }

    /**
     * Execute the query and return results.
     */
    public function getResults(): AuditEntryCollection
    {
        $logs = $this->fetchLogs($this->limit);
        $entries = array_map(static fn (AuditLog $log) => new AuditEntry($log), $logs);

        return new AuditEntryCollection(array_values($entries));
    }

    /**
     * Count matching results.
     */
    public function count(): int
    {
        if ($this->changedFields !== []) {
            return $this->getResults()->count();
        }

        $filters = $this->buildFilters();
        unset($filters['afterId'], $filters['beforeId']);

        return count($this->repository->findWithFilters($filters, PHP_INT_MAX));
    }

    /**
     * @return array<AuditLog>
     */
    private function fetchLogs(int $limit): array
    {
        $logs = $this->repository->findWithFilters($this->buildFilters(), $limit);

        return $this->changedFields !== [] ? $this->filterByChangedFields($logs) : $logs;
    }

    /**
     * Get the first result or null.
     */
    public function getFirstResult(): ?AuditEntry
    {
        return $this->limit(1)->getResults()->first();
    }

    /**
     * Check if any results exist.
     */
    public function exists(): bool
    {
        return null !== $this->getFirstResult();
    }

    /**
     * Get the cursor (last ID) for pagination.
     */
    public function getNextCursor(): ?string
    {
        return $this->getResults()->last()?->id?->toRfc4122();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(): array
    {
        $filters = array_filter([
            'entityClass' => $this->entityClass,
            'entityId' => $this->entityId,
            'userId' => $this->userId,
            'transactionHash' => $this->transactionHash,
            'afterId' => $this->afterId,
            'beforeId' => $this->beforeId,
            'action' => 1 === count($this->actions) ? $this->actions[0] : null,
        ], static fn ($v) => $v !== null);

        if ($this->since !== null) {
            $filters['from'] = DateTimeImmutable::createFromInterface($this->since);
        }

        if ($this->until !== null) {
            $filters['to'] = DateTimeImmutable::createFromInterface($this->until);
        }

        return $filters;
    }

    /**
     * Filter logs by changed fields (post-fetch).
     *
     * @param array<AuditLog> $logs
     *
     * @return array<AuditLog>
     */
    private function filterByChangedFields(array $logs): array
    {
        return array_values(array_filter($logs, fn (AuditLog $log) => array_any(
            $this->changedFields,
            static fn ($f) => in_array($f, $log->changedFields ?? [], true)
        )));
    }
}
