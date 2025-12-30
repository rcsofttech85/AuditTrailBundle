<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
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
        public private(set) AuditLogInterface $auditLog,
        public readonly object $entity,
    ) {
    }

    public function getAuditLog(): AuditLogInterface
    {
        return $this->auditLog;
    }

    /**
     * Get the entity class name.
     */
    public string $entityClass {
        get {
            return $this->auditLog->getEntityClass();
        }
    }

    /**
     * Get the action performed (create, update, delete, soft_delete, restore).
     */
    public string $action {
        get {
            return $this->auditLog->getAction();
        }
    }
}
