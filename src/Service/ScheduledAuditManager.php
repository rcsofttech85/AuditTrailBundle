<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use OverflowException;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function sprintf;

final class ScheduledAuditManager implements ScheduledAuditManagerInterface
{
    private const int MAX_SCHEDULED_AUDITS = 1000;

    /**
     * @var array<int, array{
     *     entity: object,
     *     audit: AuditLog,
     *     is_insert: bool
     * }>
     */
    public private(set) array $scheduledAudits = [];

    /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> */
    public private(set) array $pendingDeletions = [];

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function schedule(
        object $entity,
        AuditLog $audit,
        bool $isInsert,
    ): void {
        if (count($this->scheduledAudits) >= self::MAX_SCHEDULED_AUDITS) {
            throw new OverflowException(sprintf('Maximum audit queue size exceeded (%d). Consider batch processing.', self::MAX_SCHEDULED_AUDITS));
        }

        $audit = $this->dispatchCreatedEvent($entity, $audit);

        $this->scheduledAudits[] = [
            'entity' => $entity,
            'audit' => $audit,
            'is_insert' => $isInsert,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addPendingDeletion(object $entity, array $data, bool $isManaged): void
    {
        $this->pendingDeletions[] = [
            'entity' => $entity,
            'data' => $data,
            'is_managed' => $isManaged,
        ];
    }

    public function clear(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
    }

    private function dispatchCreatedEvent(
        object $entity,
        AuditLog $audit,
    ): AuditLog {
        if ($this->eventDispatcher === null) {
            return $audit;
        }

        $event = new AuditLogCreatedEvent($audit, $entity);
        $this->eventDispatcher->dispatch($event, AuditLogCreatedEvent::NAME);

        return $event->auditLog;
    }
}
