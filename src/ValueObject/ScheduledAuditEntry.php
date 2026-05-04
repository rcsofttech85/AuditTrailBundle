<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

final readonly class ScheduledAuditEntry
{
    public function __construct(
        public object $entity,
        public AuditLog $audit,
        public bool $isInsert,
    ) {
    }
}
