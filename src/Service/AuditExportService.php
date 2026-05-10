<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;

final readonly class AuditExportService
{
    public function __construct(
        private AuditLogRepositoryInterface $repository,
        private AuditExporterInterface $exporter,
        private AuditExportFileWriter $fileWriter,
    ) {
    }

    public function export(AuditExportInput $input): AuditExportResult
    {
        $batch = new LimitedAuditExportBatch(
            $this->repository->findAllWithFilters($input->filters),
            $input->limit,
        );

        if (!$batch->hasItems()) {
            return new AuditExportResult($input->outputTarget, 0);
        }

        $writeResult = $this->fileWriter->write($input->outputTarget, function (mixed $handle) use ($batch, $input): int {
            return $this->exporter->exportToStream($batch, $input->format, $handle);
        });

        $formattedSize = $input->writesToFile() && $writeResult->size !== null
            ? $this->exporter->formatFileSize($writeResult->size)
            : null;

        return new AuditExportResult($input->outputTarget, $writeResult->count, $writeResult->size, $formattedSize);
    }
}
