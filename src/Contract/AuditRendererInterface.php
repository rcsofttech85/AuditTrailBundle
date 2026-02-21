<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Console\Output\OutputInterface;

interface AuditRendererInterface
{
    /**
     * @param array<AuditLog> $audits
     */
    public function renderTable(OutputInterface $output, array $audits, bool $showDetails): void;

    /**
     * @return array<int, mixed>
     */
    public function buildRow(AuditLog $audit, bool $showDetails): array;

    public function formatChangedDetails(AuditLog $audit): string;

    public function formatValue(mixed $value): string;
}
