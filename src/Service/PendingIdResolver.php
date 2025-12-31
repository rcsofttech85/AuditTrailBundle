<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

trait PendingIdResolver
{
    /**
     * @param array<string, mixed> $context
     */
    private function resolveEntityId(
        AuditLogInterface $log,
        array $context,
    ): ?string {
        return EntityIdResolver::resolve($log, $context);
    }
}
