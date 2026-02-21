<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Message;

use DateTimeInterface;
use JsonSerializable;
use Override;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Represents an audit log message for queue transport.
 */
#[AsMessage(transport: 'audit_trail')]
readonly class AuditLogMessage implements JsonSerializable
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<int, string>|null   $changedFields
     * @param array<string, mixed>      $context
     */
    public function __construct(
        public string $entityClass,
        public string $entityId,
        public string $action,
        public ?array $oldValues,
        public ?array $newValues,
        public ?array $changedFields,
        public ?string $userId,
        public ?string $username,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $transactionHash,
        public string $createdAt,
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
            context: $log->context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'action' => $this->action,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'changed_fields' => $this->changedFields,
            'user_id' => $this->userId,
            'username' => $this->username,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'transaction_hash' => $this->transactionHash,
            'created_at' => $this->createdAt,
            'context' => $this->context,
        ];
    }
}
