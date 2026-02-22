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
use Stringable;
use Throwable;

use function gettype;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_scalar;
use function is_string;
use function json_encode;
use function ksort;
use function method_exists;
use function preg_match;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

final class AuditIntegrityService implements AuditIntegrityServiceInterface
{
    private readonly DateTimeZone $utc;

    /**
     * @var bool Read-only property check for integrity status using PHP 8.4 hooks.
     */
    public bool $isEnabled {
        get => $this->enabled && $this->secret !== null;
    }

    public function __construct(
        private(set) ?string $secret = null,
        public private(set) bool $enabled = false,
        public private(set) string $algorithm = 'sha256',
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    private const int MAX_NORMALIZATION_DEPTH = 5;

    #[Override]
    public function isEnabled(): bool
    {
        return $this->isEnabled;
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
        if (!$this->isEnabled) {
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
        return match (true) {
            $value === null => 'n:',
            is_bool($value) => sprintf('b:%d', $value ? 1 : 0),
            is_int($value) => sprintf('i:%d', $value),
            is_float($value) => sprintf('f:%F', $value),
            is_string($value) => $this->normalizeString($value),
            $value instanceof DateTimeInterface => $this->normalizeDateTime($value),
            is_array($value) => $this->normalizeArray($value, $depth),
            is_object($value) => $this->normalizeObject($value),
            default => sprintf('s:%s', gettype($value)),
        };
    }

    private function normalizeDateTime(DateTimeInterface $value): string
    {
        $dt = DateTimeImmutable::createFromInterface($value);

        return sprintf('d:%s', $dt->setTimezone($this->utc)->format(DateTimeInterface::ATOM));
    }

    private function normalizeObject(object $value): string
    {
        if (method_exists($value, 'getId')) {
            /** @var mixed $id */
            $id = $value->getId();

            if (is_scalar($id) || $id instanceof Stringable || (is_object($id) && method_exists($id, '__toString'))) {
                return sprintf('s:%s', (string) $id);
            }

            return sprintf('o:%s', $value::class);
        }

        return $value instanceof Stringable || method_exists($value, '__toString')
            ? sprintf('s:%s', (string) $value)
            : sprintf('o:%s', $value::class);
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
     * @param array<mixed> $value
     */
    private function normalizeArray(array $value, int $depth): mixed
    {
        if ($depth >= self::MAX_NORMALIZATION_DEPTH) {
            return 's:[max_depth]';
        }

        if (isset($value['date'], $value['timezone'])) {
            $dateVal = $value['date'];
            $tzVal = $value['timezone'];
            if (is_string($dateVal) && is_string($tzVal)) {
                try {
                    $dt = new DateTimeImmutable($dateVal, new DateTimeZone($tzVal));

                    return $this->normalizeDateTime($dt);
                } catch (Throwable) {
                }
            }
        }

        return $this->normalizeValues($value, $depth);
    }
}
