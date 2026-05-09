<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogMessageFactoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches audit logs for async database persistence via Symfony Messenger.
 *
 * The message is consumed by PersistAuditLogHandler, which hydrates
 * and persists the AuditLog entity in a separate worker process.
 */
final readonly class AsyncDatabaseAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private AuditLogMessageFactoryInterface $messageFactory,
    ) {
    }

    #[Override]
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        $message = $this->messageFactory->createPersistMessage($context);

        $this->bus->dispatch($message);

        return AuditDeliveryResult::delivered();
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        return $context->phase->isAsyncDispatchPhase() && $context->audit->hasResolvedEntityId();
    }
}
