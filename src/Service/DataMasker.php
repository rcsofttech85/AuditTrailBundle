<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function preg_replace;
use function preg_split;
use function strtolower;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

final class DataMasker implements DataMaskerInterface
{
    private const array ALWAYS_SENSITIVE_KEYS = [
        'password',
        'secret',
        'auth',
        'authorization',
        'cookie',
        'session',
    ];

    private const array CONTEXTUAL_SENSITIVE_KEYS = [
        'key',
        'token',
    ];

    private const array SECRET_CONTEXT_SEGMENTS = [
        'api',
        'access',
        'refresh',
        'bearer',
        'csrf',
        'auth',
        'authorization',
        'secret',
        'session',
        'cookie',
        'private',
        'client',
    ];

    /** @var array<string, true> */
    private readonly array $alwaysSensitiveKeyLookup;

    /** @var array<string, true> */
    private readonly array $contextualSensitiveKeyLookup;

    /** @var array<string, true> */
    private readonly array $explicitSensitiveKeyLookup;

    /**
     * @param list<string> $sensitiveKeys
     */
    public function __construct(array $sensitiveKeys = [...self::ALWAYS_SENSITIVE_KEYS, ...self::CONTEXTUAL_SENSITIVE_KEYS])
    {
        $normalizedKeys = array_map($this->normalizeKey(...), $sensitiveKeys);
        $this->alwaysSensitiveKeyLookup = array_fill_keys(
            array_values(array_intersect($normalizedKeys, self::ALWAYS_SENSITIVE_KEYS)),
            true,
        );
        $this->contextualSensitiveKeyLookup = array_fill_keys(
            array_values(array_intersect($normalizedKeys, self::CONTEXTUAL_SENSITIVE_KEYS)),
            true,
        );
        $this->explicitSensitiveKeyLookup = array_fill_keys(
            array_values(array_diff($normalizedKeys, self::CONTEXTUAL_SENSITIVE_KEYS)),
            true,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $data[$key] = '********';
                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = $this->normalizeKey($key);

        if (isset($this->explicitSensitiveKeyLookup[$normalizedKey])) {
            return true;
        }

        return $this->containsSensitiveSegments($this->splitKeySegments($normalizedKey));
    }

    private function normalizeKey(string $key): string
    {
        $normalizedKey = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key)) ?? $key;

        return strtolower($normalizedKey);
    }

    /**
     * @return list<string>
     */
    private function splitKeySegments(string $normalizedKey): array
    {
        $segments = preg_split('/[^a-z0-9]+/', $normalizedKey, -1, PREG_SPLIT_NO_EMPTY);

        return $segments === false ? [] : $segments;
    }

    /**
     * @param list<string> $segments
     */
    private function containsSensitiveSegments(array $segments): bool
    {
        foreach ($segments as $index => $segment) {
            if (isset($this->alwaysSensitiveKeyLookup[$segment])) {
                return true;
            }

            if ($this->isContextuallySensitiveSegment($segments, $index, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $segments
     */
    private function isContextuallySensitiveSegment(array $segments, int $index, string $segment): bool
    {
        if (!isset($this->contextualSensitiveKeyLookup[$segment])) {
            return false;
        }

        return $this->isSecretContextSegment($segments[$index - 1] ?? null)
            || $this->isSecretContextSegment($segments[$index + 1] ?? null);
    }

    private function isSecretContextSegment(?string $segment): bool
    {
        return $segment !== null && in_array($segment, self::SECRET_CONTEXT_SEGMENTS, true);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $sensitiveFields
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function mask(array $data, array $sensitiveFields): array
    {
        foreach ($sensitiveFields as $field => $mask) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $mask;
            }
        }

        return $data;
    }
}
