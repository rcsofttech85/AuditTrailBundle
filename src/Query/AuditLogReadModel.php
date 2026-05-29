<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

/**
 * Read-only projection passed to AI processors.
 */
final readonly class AuditLogReadModel
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<int, string>|null   $changedFields
     * @param array<string, mixed>      $context
     */
    public function __construct(
        public string $entityClass,
        public ?string $entityId,
        public AuditAction $action,
        public DateTimeImmutable $createdAt,
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public ?array $changedFields = null,
        public ?string $transactionHash = null,
        public ?string $userId = null,
        public ?string $username = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public array $context = [],
    ) {
    }

    public static function fromAuditLog(AuditLog $audit): self
    {
        return new self(
            entityClass: $audit->entityClass,
            entityId: $audit->entityId,
            action: $audit->action,
            createdAt: $audit->createdAt,
            oldValues: $audit->oldValues,
            newValues: $audit->newValues,
            changedFields: $audit->changedFields,
            transactionHash: $audit->transactionHash,
            userId: $audit->userId,
            username: $audit->username,
            ipAddress: $audit->ipAddress,
            userAgent: $audit->userAgent,
            context: $audit->context,
        );
    }
}
