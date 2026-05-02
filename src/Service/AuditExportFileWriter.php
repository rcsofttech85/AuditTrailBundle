<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use RuntimeException;

use function dirname;
use function fclose;
use function filesize;
use function fopen;
use function is_dir;
use function is_resource;
use function mkdir;
use function sprintf;

final class AuditExportFileWriter
{
    public function ensureDirectoryExists(string $outputFile): void
    {
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $directory));
        }
    }

    /**
     * @param callable(mixed): void $writer
     */
    public function write(string $outputFile, callable $writer): int
    {
        $this->ensureDirectoryExists($outputFile);

        $handle = @fopen($outputFile, 'w');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Failed to write to file: %s', $outputFile));
        }

        try {
            $writer($handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $size = filesize($outputFile);

        return $size === false ? 0 : $size;
    }
}
