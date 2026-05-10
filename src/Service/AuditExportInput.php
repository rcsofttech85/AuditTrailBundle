<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use function str_starts_with;

final readonly class AuditExportInput
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public string $outputTarget,
        public string $format,
        public int $limit,
        public array $filters = [],
    ) {
    }

    public function writesToFile(): bool
    {
        return !str_starts_with($this->outputTarget, 'php://');
    }
}
