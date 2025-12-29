<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\DiffGeneratorInterface;

final class DiffGenerator implements DiffGeneratorInterface
{
    private const array IGNORED_FIELDS = [
        'createdAt',
        'updatedAt',
        'deletedAt',
    ];

    #[\Override]
    public function generate(?array $oldValues, ?array $newValues, array $options = []): array
    {
        $oldValues ??= [];
        $newValues ??= [];

        $raw = (bool) ($options['raw'] ?? false);
        $includeTimestamps = (bool) ($options['include_timestamps'] ?? false);

        $diff = [];

        // Merge keys from both arrays and ensure uniqueness
        $allKeys = array_keys($oldValues + $newValues);

        foreach ($allKeys as $key) {
            // Skip ignored fields unless timestamps are explicitly requested
            if (!$includeTimestamps && in_array($key, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $old = $oldValues[$key] ?? null;
            $new = $newValues[$key] ?? null;

            if (!$raw) {
                $old = $this->normalize($old);
                $new = $this->normalize($new);
            }

            // Strict comparison
            if ($old === $new) {
                continue;
            }

            $diff[$key] = [
                'old' => $old,
                'new' => $new,
            ];
        }

        return $diff;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s e');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);

            // We only want to prettify if the decoded JSON string is actually an array structure
            if (is_array($decoded)) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return $value;
    }
}
