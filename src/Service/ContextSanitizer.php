<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use BackedEnum;
use DateTimeInterface;
use Stringable;
use UnitEnum;

use function get_debug_type;
use function get_resource_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function mb_check_encoding;
use function mb_strcut;
use function method_exists;
use function sprintf;
use function strlen;

final readonly class ContextSanitizer
{
    public const int MAX_CONTEXT_BYTES = 65_536;

    public const int MAX_CONTEXT_DEPTH = 5;

    public const int MAX_STRING_BYTES = 8_192;

    private const string TRUNCATION_SUFFIX = '[truncated]';

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    public function sanitizeArray(array $values, int $depth = 0): array
    {
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return ['_max_depth_reached' => true];
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[(string) $key] = $this->sanitizeValue($value, $depth + 1);
        }

        return $sanitized;
    }

    public function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $this->sanitizeString($value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => $this->sanitizeArray($value, $depth),
            is_resource($value) => sprintf('[resource:%s]', get_resource_type($value)),
            is_object($value) && ($value instanceof Stringable || method_exists($value, '__toString')) => $this->sanitizeString((string) $value),
            is_object($value) => $value::class,
            default => sprintf('[%s]', get_debug_type($value)),
        };
    }

    public function sanitizeString(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '[invalid utf-8]';
        }

        if (strlen($value) <= self::MAX_STRING_BYTES) {
            return $value;
        }

        return mb_strcut(
            $value,
            0,
            self::MAX_STRING_BYTES - strlen(self::TRUNCATION_SUFFIX),
            'UTF-8',
        ).self::TRUNCATION_SUFFIX;
    }
}
