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

        if ($state->changedFields === []) {
            return $this->repository->countWithFilters($filters);
        }

        return $this->countMatchingResults($state, $filters);
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
     */
    private function countMatchingResults(AuditQueryState $state, array $filters): int
    {
        $count = 0;
        $cursor = null;

        do {
            $batch = $this->fetchFilterBatch($filters, $cursor);
            $count += $state->changedFields === []
                ? count($batch)
                : $this->changedFieldMatcher->countMatches($batch, $state->changedFields);
            $cursor = $this->resolveBatchCursor($batch);
        } while ($this->shouldContinueFilteredBatchLoop($batch, $cursor));

        return $count;
    }

    /**
     * @return list<AuditLog>
     */
    private function fetchLogs(AuditQueryState $state, int $limit): array
    {
        if ($state->changedFields === []) {
            /** @var list<AuditLog> $logs */
            $logs = $this->repository->findWithFilters($this->buildFilters($state), $limit);

            return $logs;
        }

        return $this->fetchLogsWithChangedFieldFilter($state, $limit);
    }

    /**
     * @return list<AuditLog>
     */
    private function fetchLogsWithChangedFieldFilter(AuditQueryState $state, int $limit): array
    {
        $results = [];
        $filters = $this->buildFilters($state);
        $cursor = $state->afterId;

        do {
            $batch = $this->fetchFilterBatch($filters, $cursor);
            foreach ($this->changedFieldMatcher->filter($batch, $state->changedFields) as $log) {
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
}
