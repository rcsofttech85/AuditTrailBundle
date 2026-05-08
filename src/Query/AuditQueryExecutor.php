<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function array_map;
use function array_slice;
use function count;

/**
 * Executes immutable AuditQueryState instances against the repository.
 *
 * @internal
 */
final readonly class AuditQueryExecutor
{
    private const int FILTER_BATCH_SIZE = 250;

    public function __construct(
        private AuditLogRepositoryInterface $repository,
        private AuditQueryFilterFactory $filterFactory,
        private AuditChangedFieldMatcher $changedFieldMatcher,
    ) {
    }

    public function getPage(AuditQueryState $state): AuditQueryPage
    {
        $logs = $this->fetchLogs($state, $state->limit);
        $entries = new AuditEntryCollection(array_map(static fn (AuditLog $log): AuditEntry => new AuditEntry($log), $logs));

        return new AuditQueryPage($entries, $this->resolveBatchCursor($logs));
    }

    public function getFirstResult(AuditQueryState $state): ?AuditEntry
    {
        return $this->getPage($state->withLimit(1))->first();
    }

    public function exists(AuditQueryState $state): bool
    {
        return $this->getFirstResult($state) !== null;
    }

    public function count(AuditQueryState $state): int
    {
        $filters = $this->buildFilters($state);
        unset($filters['afterId'], $filters['beforeId']);

        if (!$state->hasChangedFieldFilter()) {
            return $this->repository->countWithFilters($filters);
        }

        $queryableRepository = $this->resolveChangedFieldQueryableRepository();
        if ($queryableRepository !== null) {
            return $queryableRepository->countWithChangedFields($filters, $state->changedFields);
        }

        return $this->countMatchingResults($filters, $state->changedFields);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(AuditQueryState $state): array
    {
        return $this->filterFactory->build(
            $state->entityClass,
            $state->entityId,
            $state->actions,
            $state->userId,
            $state->transactionHash,
            $state->since,
            $state->until,
            $state->afterId,
            $state->beforeId,
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     */
    private function countMatchingResults(array $filters, array $changedFields): int
    {
        $count = 0;
        $cursor = null;

        do {
            $batch = $this->fetchFilterBatch($filters, $cursor);
            $count += $this->changedFieldMatcher->countMatches($batch, $changedFields);
            $cursor = $this->resolveBatchCursor($batch);
        } while ($this->shouldContinueFilteredBatchLoop($batch, $cursor));

        return $count;
    }

    /**
     * @return list<AuditLog>
     */
    private function fetchLogs(AuditQueryState $state, int $limit): array
    {
        $filters = $this->buildFilters($state);
        if (!$state->hasChangedFieldFilter()) {
            /** @var list<AuditLog> $logs */
            $logs = $this->repository->findWithFilters($filters, $limit);

            return $logs;
        }

        $queryableRepository = $this->resolveChangedFieldQueryableRepository();
        if ($queryableRepository !== null) {
            return $queryableRepository->findWithChangedFields($filters, $state->changedFields, $limit);
        }

        return $this->fetchLogsWithChangedFieldFilter($filters, $state->changedFields, $state->afterId, $limit);
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     *
     * @return list<AuditLog>
     */
    private function fetchLogsWithChangedFieldFilter(
        array $filters,
        array $changedFields,
        ?string $afterId,
        int $limit,
    ): array {
        $results = [];
        $cursor = $afterId;

        do {
            $batch = $this->fetchFilterBatch($filters, $cursor);
            foreach ($this->changedFieldMatcher->filter($batch, $changedFields) as $log) {
                $results[] = $log;
            }
            $cursor = $this->resolveBatchCursor($batch);
        } while ($limit > count($results) && $this->shouldContinueFilteredBatchLoop($batch, $cursor));

        /** @var list<AuditLog> */
        return array_slice($results, 0, $limit);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<AuditLog>
     */
    private function fetchFilterBatch(array $filters, ?string $cursor): array
    {
        $batchFilters = $filters;
        $batchFilters['afterId'] = $cursor;

        /** @var list<AuditLog> $batch */
        $batch = $this->repository->findWithFilters($batchFilters, self::FILTER_BATCH_SIZE);

        return $batch;
    }

    /**
     * @param list<AuditLog> $batch
     */
    private function resolveBatchCursor(array $batch): ?string
    {
        return $batch === [] ? null : $batch[array_key_last($batch)]->id?->toRfc4122();
    }

    /**
     * @param list<AuditLog> $batch
     */
    private function shouldContinueFilteredBatchLoop(array $batch, ?string $cursor): bool
    {
        return $batch !== [] && $cursor !== null && count($batch) === self::FILTER_BATCH_SIZE;
    }

    private function resolveChangedFieldQueryableRepository(): ?ChangedFieldQueryableAuditLogRepositoryInterface
    {
        if (!$this->repository instanceof ChangedFieldQueryableAuditLogRepositoryInterface) {
            return null;
        }

        return $this->repository->supportsChangedFieldQueries() ? $this->repository : null;
    }
}
