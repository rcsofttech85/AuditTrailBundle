<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

class MockScheduledAuditManager implements ScheduledAuditManagerInterface
{
    /** @var array<int, array{entity: object, audit: AuditLog, is_insert: bool}> */
    public array $scheduledAudits = [];

    /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> */
    public array $pendingDeletions = [];

    public function schedule(object $entity, AuditLog $audit, bool $isInsert): void
    {
        $this->scheduledAudits[] = ['entity' => $entity, 'audit' => $audit, 'is_insert' => $isInsert];
    }

    public function addPendingDeletion(object $entity, array $data, bool $isManaged): void
    {
        $this->pendingDeletions[] = ['entity' => $entity, 'data' => $data, 'is_managed' => $isManaged];
    }

    public function clear(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
    }

    /** @param array<int, array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits */
    public function replaceScheduledAudits(array $scheduledAudits): void
    {
        $this->scheduledAudits = $scheduledAudits;
    }

    /** @param list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $pendingDeletions */
    public function replacePendingDeletions(array $pendingDeletions): void
    {
        $this->pendingDeletions = $pendingDeletions;
    }

    private bool $enabled = true;

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
