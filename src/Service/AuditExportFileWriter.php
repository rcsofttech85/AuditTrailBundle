<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use RuntimeException;

use function dirname;
use function fclose;
use function filesize;
use function fopen;
use function ftell;
use function is_dir;
use function is_resource;
use function mkdir;
use function sprintf;
use function str_starts_with;

final class AuditExportFileWriter
{
    public function ensureDirectoryExists(string $outputFile): void
    {
        if ($this->isStreamTarget($outputFile)) {
            return;
        }

        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $directory));
        }
    }

    /**
     * @param callable(mixed): int $writer
     */
    public function write(string $outputFile, callable $writer): AuditExportWriteResult
    {
        $this->ensureDirectoryExists($outputFile);

        $handle = @fopen($outputFile, 'w');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Failed to write to file: %s', $outputFile));
        }

        if ($this->isStreamTarget($outputFile)) {
            try {
                $count = $writer($handle);
                $position = ftell($handle);

                return new AuditExportWriteResult($count, $position === false ? null : $position);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        try {
            $count = $writer($handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $size = filesize($outputFile);

        return new AuditExportWriteResult($count, $size === false ? 0 : $size);
    }

    private function isStreamTarget(string $outputFile): bool
    {
        return str_starts_with($outputFile, 'php://');
    }
}
