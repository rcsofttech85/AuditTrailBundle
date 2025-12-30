<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ScheduledAuditManager
{
    private const int MAX_SCHEDULED_AUDITS = 1000;

    /**
     * @var array<int, array{
     *     entity: object,
     *     audit: \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface,
     *     is_insert: bool
     * }>
     */
    private array $scheduledAudits = [];

    /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> */
    private array $pendingDeletions = [];

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function schedule(
        object $entity,
        \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface $audit,
        bool $isInsert,
    ): void {
        if (\count($this->scheduledAudits) >= self::MAX_SCHEDULED_AUDITS) {
            throw new \OverflowException(\sprintf(
                'Maximum audit queue size exceeded (%d). Consider batch processing.',
                self::MAX_SCHEDULED_AUDITS
            ));
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

    /**
     * @return array<int, array{
     *     entity: object,
     *     audit: \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface,
     *     is_insert: bool
     * }>
     */
    public function getScheduledAudits(): array
    {
        return $this->scheduledAudits;
    }

    /**
     * @return list<array{entity: object, data: array<string, mixed>, is_managed: bool}>
     */
    public function getPendingDeletions(): array
    {
        return $this->pendingDeletions;
    }

    public function clear(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
    }

    public function hasScheduledAudits(): bool
    {
        return [] !== $this->scheduledAudits;
    }

    public function countScheduled(): int
    {
        return count($this->scheduledAudits);
    }

    private function dispatchCreatedEvent(
        object $entity,
        \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface $audit,
    ): \Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface {
        if (null === $this->eventDispatcher) {
            return $audit;
        }

        $event = new AuditLogCreatedEvent($audit, $entity);
        $this->eventDispatcher->dispatch($event, AuditLogCreatedEvent::NAME);

        return $event->getAuditLog();
    }
}
