<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditExporterInterface
{
    /**
     * @param iterable<AuditLog> $audits
     */
    public function formatAudits(iterable $audits, string $format): string;

    public function formatFileSize(int $bytes): string;
}
