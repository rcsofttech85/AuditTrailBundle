<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Factory;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogMessageFactoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

/**
 * Centralises the creation of message DTOs from AuditLog entities.
 *
 * Both QueueAuditTransport and AsyncDatabaseAuditTransport delegate here,
 * eliminating duplicated entity-ID resolution and DTO mapping logic (DRY).
 */
final readonly class AuditLogMessageFactory implements AuditLogMessageFactoryInterface
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
    ) {
    }

    /**
     * Create a message for the Queue transport (external dispatch).
     */
    public function createQueueMessage(AuditTransportContext $context): AuditLogMessage
    {
        $entityId = $this->resolveEntityId($context);

        return AuditLogMessage::createFromAuditLog($context->audit, $entityId);
    }

    /**
     * Create a message for the async Database transport (internal persistence).
     */
    public function createPersistMessage(AuditTransportContext $context): PersistAuditLogMessage
    {
        $entityId = $this->resolveEntityId($context);

        return PersistAuditLogMessage::createFromAuditLog($context->audit, $entityId);
    }

    private function resolveEntityId(AuditTransportContext $context): string
    {
        return $this->idResolver->resolve($context->audit, $context) ?? $context->audit->entityId;
    }
}
