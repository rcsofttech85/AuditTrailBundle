<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;

use function array_key_exists;
use function is_array;
use function str_contains;
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

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            foreach (self::DEFAULT_SENSITIVE_KEYS as $sensitive) {
                if (str_contains(strtolower($key), $sensitive)) {
                    $data[$key] = '********';
                    continue 2;
                }
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
