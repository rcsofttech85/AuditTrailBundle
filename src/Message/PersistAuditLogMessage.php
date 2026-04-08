<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Message;

use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Internal message for async database persistence.
 *
 * Unlike AuditLogMessage (designed for external dispatch), this message
 * is consumed by the built-in PersistAuditLogHandler to store audit logs
 * in the local database asynchronously.
 */
#[AsMessage(transport: 'audit_trail_database')]
readonly class PersistAuditLogMessage
{
    public function __construct(
        public string $entityClass,
        public string $entityId,
        public string $action,
        /** @var array<string, mixed>|null */
        public ?array $oldValues,
        /** @var array<string, mixed>|null */
        public ?array $newValues,
        /** @var array<int, string>|null */
        public ?array $changedFields,
        public ?string $userId,
        public ?string $username,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $transactionHash,
        public string $createdAt,
        public ?string $signature = null,
        public ?string $deliveryId = null,
        /** @var array<string, mixed> */
        public array $context = [],
    ) {
    }

    public static function createFromAuditLog(AuditLog $log, ?string $resolvedEntityId = null): self
    {
        return new self(
            entityClass: $log->entityClass,
            entityId: $resolvedEntityId ?? $log->entityId,
            action: $log->action,
            oldValues: $log->oldValues,
            newValues: $log->newValues,
            changedFields: $log->changedFields,
            userId: $log->userId,
            username: $log->username,
            ipAddress: $log->ipAddress,
            userAgent: $log->userAgent,
            transactionHash: $log->transactionHash,
            createdAt: $log->createdAt->format(DateTimeInterface::ATOM),
            signature: $log->signature,
            deliveryId: Uuid::v7()->toRfc4122(),
            context: $log->context,
        );
    }
}
