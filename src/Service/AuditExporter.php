<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeInterface;
use InvalidArgumentException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use RuntimeException;
use Stringable;

use function in_array;
use function is_array;
use function is_scalar;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final readonly class AuditExporter implements AuditExporterInterface
{
    /**
     * @param iterable<AuditLog> $audits
     */
    #[Override]
    public function formatAudits(iterable $audits, string $format): string
    {
        return match ($format) {
            'json' => $this->formatAsJson($audits),
            'csv' => $this->formatAsCsv($audits),
            default => throw new InvalidArgumentException(sprintf('Unsupported format: %s', $format)),
        };
    }

    /**
     * @param iterable<AuditLog> $audits
     */
    public function formatAsJson(iterable $audits): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new RuntimeException('Failed to open temp stream for JSON generation');
        }

        try {
            fwrite($output, '[');
            $first = true;

            foreach ($audits as $audit) {
                if (!$first) {
                    fwrite($output, ',');
                }
                fwrite($output, json_encode($this->auditToArray($audit), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $first = false;
            }

            fwrite($output, ']');
            rewind($output);
            $json = stream_get_contents($output);

            return $json !== false ? $json : '[]';
        } finally {
            fclose($output);
        }
    }

    /**
     * @param iterable<AuditLog> $audits
     */
    public function formatAsCsv(iterable $audits): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new RuntimeException('Failed to open temp stream for CSV generation');
        }

        try {
            $headerWritten = false;

            foreach ($audits as $audit) {
                $row = $this->auditToArray($audit);
                if (!$headerWritten) {
                    fputcsv($output, array_keys($row), ',', '"', '\\');
                    $headerWritten = true;
                }

                $csvRow = array_map(
                    fn ($value) => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $this->sanitizeCsvValue((string) (is_scalar($value) || $value instanceof Stringable ? $value : '')),
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
     * @param AuditLog|array<string, mixed> $audit
     *
     * @return array<string, mixed>
     */
    public function auditToArray(AuditLog|array $audit): array
    {
        if (is_array($audit)) {
            return $audit;
        }

        return [
            'id' => $audit->id?->toRfc4122(),
            'entity_class' => $audit->entityClass,
            'entity_id' => $audit->entityId,
            'action' => $audit->action,
            'old_values' => $audit->oldValues,
            'new_values' => $audit->newValues,
            'changed_fields' => $audit->changedFields,
            'user_id' => $audit->userId,
            'username' => $audit->username,
            'ip_address' => $audit->ipAddress,
            'user_agent' => $audit->userAgent,
            'created_at' => $audit->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return sprintf('%.2f %s', $bytes / (1024 ** $i), $units[$i] ?? 'B');
    }

    private function sanitizeCsvValue(string $value): string
    {
        if ($value !== '' && in_array(mb_substr($value, 0, 1), ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
