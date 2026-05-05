<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

final readonly class AuditExportWriteResult
{
    public function __construct(
        public int $count,
        public ?int $size = null,
    ) {
    }
}
