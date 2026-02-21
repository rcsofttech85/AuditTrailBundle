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
}
