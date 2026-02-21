<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface SoftDeleteHandlerInterface
{
    public function isSoftDeleted(object $entity): bool;

    public function restoreSoftDeleted(object $entity): void;

    /**
     * @return array<string>
     */
    public function disableSoftDeleteFilters(): array;

    /**
     * @param array<string> $names
     */
    public function enableFilters(array $names): void;
}
