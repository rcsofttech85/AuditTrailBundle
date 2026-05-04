<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final readonly class PendingDeletionEntry
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public object $entity,
        public array $data,
        public bool $isManaged,
        public AuditAction $action,
    ) {
    }
}
