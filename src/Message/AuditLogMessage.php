<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Message;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'audit_trail')]
final readonly class AuditLogMessage implements \JsonSerializable
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
        public ?string $signature,
        public array $context,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function createFromAuditLog(AuditLogInterface $log, string $entityId): self
    {
        return new self(
            $log->getEntityClass(),
            $entityId,
            $log->getAction(),
            $log->getOldValues(),
            $log->getNewValues(),
            $log->getChangedFields(),
            $log->getUserId(),
            $log->getUsername(),
            $log->getIpAddress(),
            $log->getUserAgent(),
            $log->getTransactionHash(),
            $log->getSignature(),
            $log->getContext(),
            $log->getCreatedAt()
        );
    }

    /**
     * @return array<string, mixed>
     */
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
            'signature' => $this->signature,
            'context' => $this->context,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
