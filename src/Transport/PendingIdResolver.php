<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

trait PendingIdResolver
{
    /**
     * @param array<string, mixed> $context
     */
    private function resolveEntityId(
        \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface $log,
        array $context,
    ): ?string {
        return EntityIdResolver::resolve($log, $context);
    }
}
