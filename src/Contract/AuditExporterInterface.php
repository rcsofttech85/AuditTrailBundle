<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditExporterInterface
{
    /**
     * @param array<AuditLog> $audits
     */
    public function formatAudits(array $audits, string $format): string;

    public function formatFileSize(int $bytes): string;
}
