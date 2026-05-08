<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Contracts\EventDispatcher\Event;

final class AuditLogCreatedEvent extends Event
{
    public function __construct(
        public private(set) AuditLog $auditLog,
        public readonly ?object $entity = null,
    ) {
    }
}
