<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface AuditTransportInterface
{
    /**
     * Send audit log to the transport.
     *
     * @param AuditLogInterface    $log     The audit log entity
     * @param array<string, mixed> $context Context data (e.g., 'phase', 'em', 'uow')
     */
    public function send(AuditLogInterface $log, array $context = []): void;

    /**
     * Check if the transport supports the given phase.
     *
     * @param string               $phase   'on_flush' or 'post_flush'
     * @param array<string, mixed> $context Context data
     */
    public function supports(string $phase, array $context = []): bool;
}
