<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Symfony\Component\Uid\Uuid;

final readonly class AuditLogAdminRequestMapper
{
    /**
     * @param array<string, array{value?: mixed, value2?: mixed, comparison?: string}> $filters
     *
     * @return array<string, mixed>
     */
    public function mapExportFilters(array $filters): array
    {
        $processedFilters = [];

        foreach ($filters as $property => $data) {
            if (!isset($data['value']) || $data['value'] === '') {
                continue;
            }

            $this->applyExportFilter($processedFilters, $property, $data);
        }

        return $processedFilters;
    }

    public function isValidCursor(string $cursor): bool
    {
        return $cursor === '' || Uuid::isValid($cursor);
    }

    public function hasConflictingCursors(string $afterId, string $beforeId): bool
    {
        return $afterId !== '' && $beforeId !== '';
    }

    /**
     * @param array<string, mixed>                                      $processedFilters
     * @param array{value?: mixed, value2?: mixed, comparison?: string} $data
     */
    private function applyExportFilter(array &$processedFilters, string $property, array $data): void
    {
        $comparison = $data['comparison'] ?? '=';
        $value = $data['value'] ?? null;
        $value2 = $data['value2'] ?? null;

        if ($property === 'createdAt') {
            $this->applyCreatedAtExportFilter($processedFilters, $comparison, $value, $value2);

            return;
        }

        $processedFilters[$property] = $value;
    }

    /**
     * @param array<string, mixed> $processedFilters
     */
    private function applyCreatedAtExportFilter(array &$processedFilters, string $comparison, mixed $value, mixed $value2): void
    {
        if ($this->canApplyBetweenRange($comparison, $value, $value2)) {
            $processedFilters['from'] = $value;
            $processedFilters['to'] = $value2;

            return;
        }

        if ($this->isLowerBoundComparison($comparison) && $this->hasFilterValue($value)) {
            $processedFilters['from'] = $value;

            return;
        }

        if ($this->isUpperBoundComparison($comparison) && $this->hasFilterValue($value)) {
            $processedFilters['to'] = $value;

            return;
        }

        if ($this->hasFilterValue($value)) {
            $processedFilters['from'] = $value;
            $processedFilters['to'] = $this->hasFilterValue($value2) ? $value2 : $value;
        }
    }

    private function canApplyBetweenRange(string $comparison, mixed $value, mixed $value2): bool
    {
        return $comparison === 'between'
            && $this->hasFilterValue($value)
            && $this->hasFilterValue($value2);
    }

    private function hasFilterValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function isLowerBoundComparison(string $comparison): bool
    {
        return $comparison === '>' || $comparison === '>=';
    }

    private function isUpperBoundComparison(string $comparison): bool
    {
        return $comparison === '<' || $comparison === '<=';
    }
}
