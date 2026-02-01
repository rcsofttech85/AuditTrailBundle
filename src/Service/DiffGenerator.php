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
        $allKeys = array_keys($oldValues + $newValues);

        foreach ($allKeys as $key) {
            if ($this->shouldSkipField($key, $includeTimestamps)) {
                continue;
            }

            $fieldDiff = $this->computeFieldDiff($key, $oldValues, $newValues, $raw);

            if (null !== $fieldDiff) {
                $diff[$key] = $fieldDiff;
            }
        }

        return $diff;
    }

    private function shouldSkipField(string $field, bool $includeTimestamps): bool
    {
        return !$includeTimestamps && \in_array($field, self::IGNORED_FIELDS, true);
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     *
     * @return array{old: mixed, new: mixed}|null
     */
    private function computeFieldDiff(string $key, array $oldValues, array $newValues, bool $raw): ?array
    {
        $old = $oldValues[$key] ?? null;
        $new = $newValues[$key] ?? null;

        if (!$raw) {
            $old = $this->normalize($old);
            $new = $this->normalize($new);
        }

        if ($old === $new) {
            return null;
        }

        return ['old' => $old, 'new' => $new];
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s e'),
            \is_array($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            \is_string($value) && json_validate($value) => $this->normalizeJsonString($value),
            default => $value,
        };
    }

    private function normalizeJsonString(string $value): string
    {
        $decoded = json_decode($value, true);

        if (!\is_array($decoded)) {
            return $value;
        }

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return false !== $encoded ? $encoded : $value;
    }
}
