<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface SoftDeleteHandlerInterface
{
    public function isSoftDeleted(object $entity): bool;

    public function restoreSoftDeleted(object $entity): void;
}
