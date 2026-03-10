<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function array_pop;
use function array_shift;
use function count;
use function end;
use function reset;

final class TransactionDrilldownService
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{
     *   logs: AuditLog[],
     *   totalItems: int,
     *   limit: int,
     *   hasNextPage: bool,
     *   hasPrevPage: bool,
     *   firstId: string|null,
     *   lastId: string|null
     * }
     */
    public function getDrilldownPage(string $transactionHash, ?string $afterId, ?string $beforeId, int $limit = 15): array
    {
        $filters = $this->prepareFilters($transactionHash, $afterId, $beforeId);
        $totalItems = $this->repository->count(['transactionHash' => $transactionHash]);

        // Fetch limit + 1 to determine if there are more records
        /** @var AuditLog[] $logs */
        $logs = $this->repository->findWithFilters($filters, $limit + 1);

        return $this->processResults($logs, $filters, $totalItems, $limit);
    }

    /**
     * @return array<string, string>
     */
    private function prepareFilters(string $transactionHash, ?string $afterId, ?string $beforeId): array
    {
        $filters = ['transactionHash' => $transactionHash];
        if ($afterId !== null && $afterId !== '') {
            $filters['afterId'] = $afterId;
        } elseif ($beforeId !== null && $beforeId !== '') {
            $filters['beforeId'] = $beforeId;
        }

        return $filters;
    }

    /**
     * @param AuditLog[]           $logs
     * @param array<string, mixed> $filters
     *
     * @return array{
     *   logs: AuditLog[],
     *   totalItems: int,
     *   limit: int,
     *   hasNextPage: bool,
     *   hasPrevPage: bool,
     *   firstId: string|null,
     *   lastId: string|null
     * }
     */
    private function processResults(array $logs, array $filters, int $totalItems, int $limit): array
    {
        $pagination = $this->calculatePaginationStatus($logs, $filters, $limit);
        $logs = $pagination['logs'];

        return [
            'logs' => $logs,
            'totalItems' => $totalItems,
            'limit' => $limit,
            'hasNextPage' => $pagination['hasNextPage'],
            'hasPrevPage' => $pagination['hasPrevPage'],
            'firstId' => $this->getLogIdReference(reset($logs)),
            'lastId' => $this->getLogIdReference(end($logs)),
        ];
    }

    /**
     * @param AuditLog[]           $logs
     * @param array<string, mixed> $filters
     *
     * @return array{logs: AuditLog[], hasNextPage: bool, hasPrevPage: bool}
     */
    private function calculatePaginationStatus(array $logs, array $filters, int $limit): array
    {
        $hasNextPage = false;
        $hasPrevPage = false;

        if (isset($filters['beforeId'])) {
            $hasNextPage = true;
            if ($limit < count($logs)) {
                $hasPrevPage = true;
                array_shift($logs);
            }
        } else {
            if (isset($filters['afterId'])) {
                $hasPrevPage = true;
            }
            if ($limit < count($logs)) {
                $hasNextPage = true;
                array_pop($logs);
            }
        }

        return [
            'logs' => $logs,
            'hasNextPage' => $hasNextPage,
            'hasPrevPage' => $hasPrevPage,
        ];
    }

    private function getLogIdReference(AuditLog|false $log): ?string
    {
        return $log !== false && $log->id !== null ? (string) $log->id : null;
    }
}
