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

    /**
     * Write audit logs directly to a stream resource, avoiding full string materialization.
     *
     * @param iterable<AuditLog> $audits
     * @param resource           $stream A writable stream resource (e.g. fopen('php://output', 'wb'))
     */
    public function exportToStream(iterable $audits, string $format, mixed $stream): void;

    public function formatFileSize(int $bytes): string;
}
