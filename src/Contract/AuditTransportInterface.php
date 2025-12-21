<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditTransportInterface
{
    /**
     * Send audit log to the transport.
     *
     * @param AuditLog $log The audit log entity
     * @param array $context Context data (e.g., 'phase', 'em', 'uow')
     */
    public function send(AuditLog $log, array $context = []): void;
}
