<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\RevertPreviewFormatter;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;
use RuntimeException;

use function is_resource;

readonly class AuditLogAdminOperations
{
    public function __construct(
        private AuditReverterInterface $reverter,
        private AuditLogRepositoryInterface $repository,
        private AuditExporterInterface $exporter,
        private RevertPreviewFormatter $formatter,
        private TransactionDrilldownService $drilldownService,
        private AuditLogAdminRequestMapper $requestMapper,
        private int $adminExportLimit,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function buildPreviewChanges(AuditLog $auditLog): array
    {
        $changes = $this->reverter->revert($auditLog, dryRun: true, force: true);
        $formattedChanges = [];
        foreach ($changes as $field => $value) {
            $formattedChanges[$field] = $this->formatter->format($value);
        }

        return $formattedChanges;
    }

    public function revert(AuditLog $auditLog): void
    {
        $this->reverter->revert($auditLog, force: true);
    }

    /**
     * @return array{
     *     logs: iterable<object>,
     *     totalItems: int,
     *     limit: int,
     *     hasNextPage: bool,
     *     hasPrevPage: bool,
     *     firstId: ?string,
     *     lastId: ?string
     * }
     */
    public function getDrilldownPage(string $transactionHash, string $afterId, string $beforeId, int $limit): array
    {
        return $this->drilldownService->getDrilldownPage($transactionHash, $afterId, $beforeId, $limit);
    }

    public function hasValidDrilldownCursors(string $afterId, string $beforeId): bool
    {
        return $this->requestMapper->isValidCursor($afterId)
            && $this->requestMapper->isValidCursor($beforeId)
            && !$this->requestMapper->hasConflictingCursors($afterId, $beforeId);
    }

    /**
     * @param array<string, array{value?: mixed, comparison?: string}> $filters
     *
     * @return array<string, mixed>
     */
    public function mapExportFilters(array $filters): array
    {
        return $this->requestMapper->mapExportFilters($filters);
    }

    /**
     * @param array<string, mixed> $filters
     * @param resource             $output
     */
    public function exportToStream(array $filters, string $format, mixed $output): void
    {
        if (!is_resource($output)) {
            throw new RuntimeException('Expected a writable stream resource for export.');
        }

        $this->exporter->exportToStream(
            $this->takeAudits($this->repository->findAllWithFilters($filters), $this->adminExportLimit),
            $format,
            $output
        );
    }

    /**
     * @param iterable<AuditLog> $audits
     *
     * @return iterable<AuditLog>
     */
    private function takeAudits(iterable $audits, int $limit): iterable
    {
        $yielded = 0;

        foreach ($audits as $audit) {
            if ($yielded >= $limit) {
                break;
            }

            ++$yielded;
            yield $audit;
        }
    }
}
