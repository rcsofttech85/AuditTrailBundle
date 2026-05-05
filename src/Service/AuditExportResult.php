<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use function str_starts_with;

final readonly class AuditExportResult
{
    public function __construct(
        public string $outputTarget,
        public int $count,
        public ?int $size = null,
        public ?string $formattedSize = null,
    ) {
    }

    public function writesToFile(): bool
    {
        return !str_starts_with($this->outputTarget, 'php://');
    }
}
