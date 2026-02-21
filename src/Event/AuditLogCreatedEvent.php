<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when an audit log is created.
 *
 * Use this event to:
 * - Add custom metadata to audit logs
 * - Send notifications on specific changes
 * - Integrate with external audit systems
 * - Filter or modify audit logs before persistence
 */
final class AuditLogCreatedEvent extends Event
{
    public const string NAME = 'audit_trail.audit_log_created';

    public function __construct(
        public private(set) AuditLog $auditLog,
        public readonly ?object $entity = null,
    ) {
    }
}
