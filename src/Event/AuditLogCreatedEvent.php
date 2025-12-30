<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

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
        private \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface $auditLog,
        private readonly object $entity,
    ) {
    }

    public function getAuditLog(): \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface
    {
        return $this->auditLog;
    }

    public function setAuditLog(\Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface $auditLog): void
    {
        $this->auditLog = $auditLog;
    }

    /**
     * The original entity that triggered the audit.
     */
    public function getEntity(): object
    {
        return $this->entity;
    }

    /**
     * Get the entity class name.
     */
    public function getEntityClass(): string
    {
        return $this->auditLog->getEntityClass();
    }

    /**
     * Get the action performed (create, update, delete, soft_delete, restore).
     */
    public function getAction(): string
    {
        return $this->auditLog->getAction();
    }
}
