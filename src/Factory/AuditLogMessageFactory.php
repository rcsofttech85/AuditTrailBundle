<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Factory;

use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;

/**
 * Centralises the creation of message DTOs from AuditLog entities.
 *
 * Both QueueAuditTransport and AsyncDatabaseAuditTransport delegate here,
 * eliminating duplicated entity-ID resolution and DTO mapping logic (DRY).
 */
readonly class AuditLogMessageFactory
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
    ) {
    }

    /**
     * Create a message for the Queue transport (external dispatch).
     *
     * @param array<string, mixed> $context
     */
    public function createQueueMessage(AuditLog $log, array $context = []): AuditLogMessage
    {
        $entityId = $this->resolveEntityId($log, $context);

        return AuditLogMessage::createFromAuditLog($log, $entityId);
    }

    /**
     * Create a message for the async Database transport (internal persistence).
     *
     * @param array<string, mixed> $context
     */
    public function createPersistMessage(AuditLog $log, array $context = []): PersistAuditLogMessage
    {
        $entityId = $this->resolveEntityId($log, $context);

        return PersistAuditLogMessage::createFromAuditLog($log, $entityId);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveEntityId(AuditLog $log, array $context): string
    {
        return $this->idResolver->resolve($log, $context) ?? $log->entityId;
    }
}
