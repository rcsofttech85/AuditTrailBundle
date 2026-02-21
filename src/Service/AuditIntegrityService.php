<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use RuntimeException;
use Throwable;

use function hash_hmac;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

final class AuditIntegrityService implements AuditIntegrityServiceInterface
{
    private readonly DateTimeZone $utc;

    public function __construct(
        private readonly ?string $secret = null,
        private readonly bool $enabled = false,
        private readonly string $algorithm = 'sha256',
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    private const int MAX_NORMALIZATION_DEPTH = 5;

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled && $this->secret !== null;
    }

    #[Override]
    public function generateSignature(AuditLog $log): string
    {
        if ($this->secret === null) {
            throw new RuntimeException('Cannot generate signature: secret key is not configured.');
        }

        $data = $this->normalizeData($log);
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    #[Override]
    public function verifySignature(AuditLog $log): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $storedSignature = $log->signature;

        if ($storedSignature === null) {
            return false;
        }

        $expectedSignature = $this->generateSignature($log);

        return hash_equals($expectedSignature, $storedSignature);
    }

    #[Override]
    public function signPayload(string $payload): string
    {
        if ($this->secret === null) {
            throw new RuntimeException('Cannot sign payload: secret key is not configured.');
        }

        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeData(AuditLog $log): array
    {
        $data = [
            'entity_class' => $log->entityClass,
            'entity_id' => $log->entityId,
            'action' => $log->action,
            'old_values' => $this->normalizeValues($log->oldValues),
            'new_values' => $this->normalizeValues($log->newValues),
            'user_id' => $log->userId,
            'username' => $log->username,
            'context' => $this->normalizeValues($log->context),
            'ip_address' => $log->ipAddress,
            'user_agent' => $log->userAgent,
            'transaction_hash' => $log->transactionHash,
            'created_at' => $log->createdAt->setTimezone($this->utc)->format('Y-m-d H:i:s'),
        ];

        ksort($data, SORT_STRING);

        return $data;
    }

    /**
     * @param array<string, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    private function normalizeValues(?array $values, int $depth = 0): ?array
    {
        if ($values === null) {
            return null;
        }

        if ($depth >= self::MAX_NORMALIZATION_DEPTH) {
            return ['_error' => 'max_depth_reached'];
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value, $depth + 1);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function normalizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($value === null) {
            return 'n:';
        }

        if (is_bool($value)) {
            return 'b:'.($value ? '1' : '0');
        }

        if (is_int($value)) {
            return 'i:'.$value;
        }

        if (is_float($value)) {
            return 'f:'.$value;
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        if (is_array($value)) {
            return $this->normalizeArray($value, $depth);
        }

        return 's:'.(string) $value;
    }

    private function normalizeString(string $value): string
    {
        if (strlen($value) >= 10 && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            try {
                $dt = new DateTimeImmutable($value);

                return 'd:'.$dt->setTimezone($this->utc)->format(DateTimeInterface::ATOM);
            } catch (Throwable) {
                // Not a date
            }
        }

        return 's:'.$value;
    }

    /**
     * @param array<string, mixed>|array{date?: string, timezone?: string} $value
     */
    private function normalizeArray(array $value, int $depth): mixed
    {
        if ($depth >= self::MAX_NORMALIZATION_DEPTH) {
            return 's:[max_depth]';
        }

        if (isset($value['date'], $value['timezone'])) {
            try {
                $dt = new DateTimeImmutable($value['date'], new DateTimeZone($value['timezone']));

                return 'd:'.$dt->setTimezone($this->utc)->format(DateTimeInterface::ATOM);
            } catch (Throwable) {
            }
        }

        return $this->normalizeValues($value, $depth);
    }
}
