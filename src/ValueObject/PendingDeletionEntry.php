<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final readonly class PendingDeletionEntry
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public object $entity,
        public array $data,
        public AuditAction $action,
        public ?AuditLog $audit = null,
    ) {
    }

    public function withAudit(AuditLog $audit): self
    {
        return new self(
            $this->entity,
            $this->data,
            $this->action,
            $audit,
        );
    }
}
