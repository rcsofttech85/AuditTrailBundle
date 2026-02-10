<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeInterface;
use InvalidArgumentException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use RuntimeException;

use function is_array;
use function sprintf;
use function strlen;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

readonly class AuditExporter
{
    /**
     * @param array<AuditLogInterface> $audits
     */
    public function formatAudits(array $audits, string $format): string
    {
        $rows = array_map([$this, 'auditToArray'], $audits);

        return match ($format) {
            'json' => json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'csv' => $this->formatAsCsv($rows),
            default => throw new InvalidArgumentException(sprintf('Unsupported format: %s', $format)),
        };
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    public function formatAsCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new RuntimeException('Failed to open temp stream for CSV generation');
        }

        try {
            fputcsv($output, array_keys($rows[0]), ',', '"', '\\');

            foreach ($rows as $row) {
                $csvRow = array_map(
                    fn ($value) => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value,
                    $row
                );
                fputcsv($output, $csvRow, ',', '"', '\\');
            }

            rewind($output);
            $csv = stream_get_contents($output);

            return $csv !== false ? $csv : '';
        } finally {
            fclose($output);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function auditToArray(AuditLogInterface $audit): array
    {
        return [
            'id' => $audit->getId(),
            'entity_class' => $audit->getEntityClass(),
            'entity_id' => $audit->getEntityId(),
            'action' => $audit->getAction(),
            'old_values' => $audit->getOldValues(),
            'new_values' => $audit->getNewValues(),
            'changed_fields' => $audit->getChangedFields(),
            'user_id' => $audit->getUserId(),
            'username' => $audit->getUsername(),
            'ip_address' => $audit->getIpAddress(),
            'user_agent' => $audit->getUserAgent(),
            'created_at' => $audit->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = (int) floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
