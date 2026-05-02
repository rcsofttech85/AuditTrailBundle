<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeImmutable;
use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

use function array_filter;
use function count;

final class AuditQueryFilterFactory
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
        $filters = array_filter([
            'entityClass' => $entityClass,
            'entityId' => $entityId,
            'userId' => $userId,
            'transactionHash' => $transactionHash,
            'afterId' => $afterId,
            'beforeId' => $beforeId,
            'action' => 1 === count($actions) ? $actions[0]->value : null,
            'actions' => count($actions) > 1 ? array_map(static fn (AuditAction $action): string => $action->value, $actions) : null,
        ], static fn ($value): bool => $value !== null);

        if ($since !== null) {
            $filters['from'] = DateTimeImmutable::createFromInterface($since);
        }

        if ($until !== null) {
            $filters['to'] = DateTimeImmutable::createFromInterface($until);
        }

        return $filters;
    }
}
