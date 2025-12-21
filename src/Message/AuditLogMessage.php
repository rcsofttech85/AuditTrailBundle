<?php

namespace Rcsofttech\AuditTrailBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'audit_trail')]
final class AuditLogMessage
{
    public function __construct(
        public readonly string $entityClass,
        public readonly string $entityId,
        public readonly string $action,
        public readonly ?array $oldValues,
        public readonly ?array $newValues,
        public readonly ?int $userId,
        public readonly ?string $username,
        public readonly ?string $ipAddress,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
