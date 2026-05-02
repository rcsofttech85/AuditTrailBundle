<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Symfony\Contracts\EventDispatcher\Event;
use Throwable;

final class AuditDeliveryFailedEvent extends Event
{
    public function __construct(
        public readonly AuditLog $auditLog,
        public readonly AuditPhase $phase,
        public readonly Throwable $transportError,
        public readonly Throwable $fallbackError,
        public readonly ?object $entity = null,
    ) {
    }
}
