<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use OverflowException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function count;
use function sprintf;

final class ScheduledAuditManager implements ScheduledAuditManagerInterface
{
    /**
     * @var array<int, array{
     *     entity: object,
     *     audit: AuditLog,
     *     is_insert: bool
     * }>
     */
    private array $scheduledAudits = [];

    /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> */
    private array $pendingDeletions = [];

    private int $disableDepth = 0;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly ?int $maxScheduledAudits = null,
    ) {
    }

    #[Override]
    public function disable(): void
    {
        ++$this->disableDepth;
    }

    #[Override]
    public function enable(): void
    {
        if ($this->disableDepth > 0) {
            --$this->disableDepth;
        }
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled && $this->disableDepth === 0;
    }

    #[Override]
    public function schedule(
        object $entity,
        AuditLog $audit,
        bool $isInsert,
    ): void {
        if ($this->maxScheduledAudits !== null && $this->maxScheduledAudits <= count($this->scheduledAudits)) {
            throw new OverflowException(sprintf('Maximum audit queue size exceeded (%d). Consider batch processing.', $this->maxScheduledAudits));
        }

        $this->scheduledAudits[] = [
            'entity' => $entity,
            'audit' => $audit,
            'is_insert' => $isInsert,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Override]
    public function addPendingDeletion(object $entity, array $data, bool $isManaged): void
    {
        $this->pendingDeletions[] = [
            'entity' => $entity,
            'data' => $data,
            'is_managed' => $isManaged,
        ];
    }

    #[Override]
    public function clear(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
    }

    /**
     * @return array<int, array{entity: object, audit: AuditLog, is_insert: bool}>
     */
    #[Override]
    public function getScheduledAudits(): array
    {
        return $this->scheduledAudits;
    }

    /**
     * @return list<array{entity: object, data: array<string, mixed>, is_managed: bool}>
     */
    #[Override]
    public function getPendingDeletions(): array
    {
        return $this->pendingDeletions;
    }

    /**
     * @internal retains only audits that still need delivery after a failed post-flush dispatch
     *
     * @param array<int, array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits
     */
    #[Override]
    public function replaceScheduledAudits(array $scheduledAudits): void
    {
        $this->scheduledAudits = $scheduledAudits;
    }

    /**
     * @internal retains only deletions that still need audit delivery after a failed post-flush dispatch
     *
     * @param list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $pendingDeletions
     */
    #[Override]
    public function replacePendingDeletions(array $pendingDeletions): void
    {
        $this->pendingDeletions = $pendingDeletions;
    }
}
