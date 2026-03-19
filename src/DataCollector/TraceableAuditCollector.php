<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DataCollector;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects audit logs dispatched during the current request.
 *
 * This subscriber listens to AuditLogCreatedEvent and stores
 * serializable summaries for the Symfony Profiler DataCollector.
 */
final class TraceableAuditCollector implements EventSubscriberInterface, ResetInterface
{
    /** @var list<array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string}> */
    public private(set) array $collectedAudits = [];

    public static function getSubscribedEvents(): array
    {
        return [
            AuditLogCreatedEvent::class => ['onAuditLogCreated', 0],
        ];
    }

    public function onAuditLogCreated(AuditLogCreatedEvent $event): void
    {
        $audit = $event->auditLog;
        $this->collectedAudits[] = self::serializeAudit($audit);
    }

    public function reset(): void
    {
        $this->collectedAudits = [];
    }

    /**
     * @return array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string}
     */
    private static function serializeAudit(AuditLog $audit): array
    {
        return [
            'entity_class' => $audit->entityClass,
            'entity_id' => $audit->entityId,
            'action' => $audit->action,
            'changed_fields' => $audit->changedFields !== null ? array_values($audit->changedFields) : null,
            'user' => $audit->username ?? $audit->userId,
            'transaction_hash' => $audit->transactionHash,
            'created_at' => $audit->createdAt->format('Y-m-d H:i:s.u'),
        ];
    }
}
