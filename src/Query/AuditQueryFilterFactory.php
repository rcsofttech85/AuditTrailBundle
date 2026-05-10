<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeImmutable;
use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final readonly class AuditQueryFilterFactory
{
    /**
     * @param list<AuditAction> $actions
     *
     * @return array<string, mixed>
     */
    public function build(
        ?string $entityClass,
        ?string $entityId,
        array $actions,
        ?string $userId,
        ?string $transactionHash,
        ?DateTimeInterface $since,
        ?DateTimeInterface $until,
        ?string $afterId,
        ?string $beforeId,
    ): array {
        return [
            ...$this->buildBaseFilters(
                $entityClass,
                $entityId,
                $userId,
                $transactionHash,
                $afterId,
                $beforeId,
            ),
            ...$this->buildActionFilters($actions),
            ...$this->buildDateFilters($since, $until),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBaseFilters(
        ?string $entityClass,
        ?string $entityId,
        ?string $userId,
        ?string $transactionHash,
        ?string $afterId,
        ?string $beforeId,
    ): array {
        $filters = [];
        $this->addNullableFilter($filters, 'entityClass', $entityClass);
        $this->addNullableFilter($filters, 'entityId', $entityId);
        $this->addNullableFilter($filters, 'userId', $userId);
        $this->addNullableFilter($filters, 'transactionHash', $transactionHash);
        $this->addNullableFilter($filters, 'afterId', $afterId);
        $this->addNullableFilter($filters, 'beforeId', $beforeId);

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function addNullableFilter(array &$filters, string $key, mixed $value): void
    {
        if ($value !== null) {
            $filters[$key] = $value;
        }
    }

    /**
     * @param list<AuditAction> $actions
     *
     * @return array<string, mixed>
     */
    private function buildActionFilters(array $actions): array
    {
        if ($actions === []) {
            return [];
        }

        if (!isset($actions[1])) {
            return ['action' => $actions[0]->value];
        }

        return ['actions' => $this->mapActionValues($actions)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDateFilters(?DateTimeInterface $since, ?DateTimeInterface $until): array
    {
        $filters = [];
        $this->addDateFilter($filters, 'from', $since);
        $this->addDateFilter($filters, 'to', $until);

        return $filters;
    }

    /**
     * @param list<AuditAction> $actions
     *
     * @return list<string>
     */
    private function mapActionValues(array $actions): array
    {
        $values = [];
        foreach ($actions as $action) {
            $values[] = $action->value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function addDateFilter(array &$filters, string $key, ?DateTimeInterface $value): void
    {
        if ($value !== null) {
            $filters[$key] = DateTimeImmutable::createFromInterface($value);
        }
    }
}
