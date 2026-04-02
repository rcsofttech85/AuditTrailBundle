<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use RuntimeException;
use Stringable;

use function count;
use function in_array;
use function is_array;
use function is_resource;
use function is_scalar;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final readonly class AuditExporter implements AuditExporterInterface
{
    private const array CSV_HEADERS = [
        'id',
        'entity_class',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'changed_fields',
        'user_id',
        'username',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    public function __construct(
        private ?EntityManagerInterface $entityManager = null,
    ) {
    }

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
     * @param resource           $stream
     */
    #[Override]
    public function exportToStream(iterable $audits, string $format, mixed $stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Expected a writable stream resource');
        }

        match ($format) {
            'json' => $this->writeJsonToStream($audits, $stream),
            'csv' => $this->writeCsvToStream($audits, $stream),
            default => throw new InvalidArgumentException(sprintf('Unsupported format: %s', $format)),
        };
    }

    /**
     * @param iterable<AuditLog> $audits
     */
    public function formatAsJson(iterable $audits): string
    {
        return $this->formatViaStream(fn ($stream) => $this->writeJsonToStream($audits, $stream));
    }

    /**
     * @param iterable<AuditLog> $audits
     */
    public function formatAsCsv(iterable $audits): string
    {
        return $this->formatViaStream(fn ($stream) => $this->writeCsvToStream($audits, $stream));
    }

    /**
     * @return array<string, mixed>
     */
    public function auditToArray(AuditLog $audit): array
    {
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

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $i), $units[$i]);
    }

    /**
     * @param iterable<AuditLog> $audits
     * @param resource           $stream
     */
    private function writeJsonToStream(iterable $audits, mixed $stream): void
    {
        fwrite($stream, '[');
        $first = true;

        foreach ($audits as $audit) {
            if (!$first) {
                fwrite($stream, ',');
            }
            fwrite($stream, json_encode($this->auditToArray($audit), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $first = false;

            $this->entityManager?->detach($audit);
        }

        fwrite($stream, ']');
    }

    /**
     * @param iterable<AuditLog> $audits
     * @param resource           $stream
     */
    private function writeCsvToStream(iterable $audits, mixed $stream): void
    {
        fputcsv($stream, self::CSV_HEADERS, ',', '"', '\\');

        foreach ($audits as $audit) {
            $row = $this->auditToArray($audit);
            $csvRow = array_map(
                fn ($value) => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $this->sanitizeCsvValue((string) (is_scalar($value) || $value instanceof Stringable ? $value : '')),
                $row
            );
            fputcsv($stream, $csvRow, ',', '"', '\\');

            $this->entityManager?->detach($audit);
        }
    }

    /**
     * Helper: run a stream-writing callback against php://temp and return the result as a string.
     *
     * @param callable(resource): void $writer
     */
    private function formatViaStream(callable $writer): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open temp stream for export');
        }

        try {
            $writer($stream);
            rewind($stream);
            $content = stream_get_contents($stream);

            return $content !== false ? $content : '';
        } finally {
            fclose($stream);
        }
    }

    private function sanitizeCsvValue(string $value): string
    {
        if ($value !== '' && in_array(mb_substr($value, 0, 1), ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
