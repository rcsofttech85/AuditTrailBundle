<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;

use function array_key_exists;
use function is_array;
use function strtolower;

final class DataMasker implements DataMaskerInterface
{
    private const array DEFAULT_SENSITIVE_KEYS = [
        'password',
        'secret',
        'key',
        'token',
        'auth',
        'authorization',
        'cookie',
        'session',
    ];

    private readonly string $sensitiveKeyPattern;

    /**
     * @param list<string> $sensitiveKeys
     */
    public function __construct(array $sensitiveKeys = self::DEFAULT_SENSITIVE_KEYS)
    {
        $this->sensitiveKeyPattern = '/'.implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $sensitiveKeys)).'/';
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
            if (preg_match($this->sensitiveKeyPattern, strtolower($key)) === 1) {
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
