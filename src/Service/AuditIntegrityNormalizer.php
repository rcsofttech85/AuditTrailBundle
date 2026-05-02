<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Stringable;
use Throwable;

use function gettype;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function method_exists;
use function sprintf;

use const SORT_STRING;

final class AuditIntegrityNormalizer
{
    private const int MAX_NORMALIZATION_DEPTH = 5;

    private const array DATE_TIME_FORMATS = [
        DateTimeInterface::ATOM,
        DateTimeInterface::RFC3339_EXTENDED,
        'Y-m-d H:i:sP',
        'Y-m-d\TH:i:sP',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s.u',
        'Y-m-d H:i:s.uP',
        'Y-m-d\TH:i:s.uP',
    ];

    private readonly DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(AuditLog $log, bool $includeChangedFields = true): array
    {
        $data = [
            'entity_class' => $log->entityClass,
            'entity_id' => $log->entityId,
            'action' => $log->action->value,
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

        if ($includeChangedFields) {
            $data['changed_fields'] = $this->normalizeValues($log->changedFields);
        }

        ksort($data, SORT_STRING);

        return $data;
    }

    /**
     * @param array<mixed>|null $values
     *
     * @return array<mixed>|null
     */
    private function normalizeValues(?array $values, int $depth = 0): ?array
    {
        if ($values === null) {
            return null;
        }

        if ($depth >= self::MAX_NORMALIZATION_DEPTH) {
            /** @var array<string, mixed> $normalized */
            $normalized = ['_error' => 'max_depth_reached'];

            return $normalized;
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value, $depth + 1);
        }

        if (!array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        /** @var array<mixed> $normalized */
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
        return $value instanceof Stringable || method_exists($value, '__toString')
            ? sprintf('s:%s', (string) $value)
            : sprintf('o:%s', $value::class);
    }

    private function normalizeString(string $value): string
    {
        $dateTime = $this->parseExplicitDateTimeString($value);
        if ($dateTime !== null) {
            return 'd:'.$dateTime->setTimezone($this->utc)->format(DateTimeInterface::ATOM);
        }

        return 's:'.$value;
    }

    private function parseExplicitDateTimeString(string $value): ?DateTimeImmutable
    {
        foreach (self::DATE_TIME_FORMATS as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value, $this->utc);

            if ($dateTime !== false && $this->hasNoDateTimeParseErrors()) {
                return $dateTime;
            }
        }

        return null;
    }

    private function hasNoDateTimeParseErrors(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();

        return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
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
                    // Keep original payload on reconstruction failure.
                }
            }
        }

        return $this->normalizeValues($value, $depth);
    }
}
