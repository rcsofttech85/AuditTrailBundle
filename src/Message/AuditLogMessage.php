<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'audit_trail')]
final readonly class AuditLogMessage
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function __construct(
        public string $entityClass,
        public string $entityId,
        public string $action,
        public ?array $oldValues,
        public ?array $newValues,
        public ?int $userId,
        public ?string $username,
        public ?string $ipAddress,
        public ?string $transactionHash,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
