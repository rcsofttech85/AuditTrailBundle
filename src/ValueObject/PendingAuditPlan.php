<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final readonly class PendingAuditPlan
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>      $newValues
     * @param list<string>              $deferredCollectionFields
     * @param array<string, mixed>      $context
     */
    private function __construct(
        public object $entity,
        public AuditAction $action,
        public ?array $oldValues = null,
        public array $newValues = [],
        public array $deferredCollectionFields = [],
        public array $context = [],
        public bool $refreshEntityData = false,
        public ?AuditLog $audit = null,
    ) {
    }

    public static function forEntityRefresh(object $entity, AuditAction $action): self
    {
        return new self(
            entity: $entity,
            action: $action,
            refreshEntityData: true,
        );
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>      $newValues
     * @param list<string>              $deferredCollectionFields
     * @param array<string, mixed>      $context
     */
    public static function forDeferredCollections(
        object $entity,
        AuditAction $action,
        ?array $oldValues,
        array $newValues,
        array $deferredCollectionFields,
        array $context = [],
    ): self {
        return new self(
            entity: $entity,
            action: $action,
            oldValues: $oldValues,
            newValues: $newValues,
            deferredCollectionFields: $deferredCollectionFields,
            context: $context,
        );
    }

    public function withAudit(AuditLog $audit): self
    {
        return new self(
            entity: $this->entity,
            action: $this->action,
            oldValues: $this->oldValues,
            newValues: $this->newValues,
            deferredCollectionFields: $this->deferredCollectionFields,
            context: $this->context,
            refreshEntityData: $this->refreshEntityData,
            audit: $audit,
        );
    }
}
