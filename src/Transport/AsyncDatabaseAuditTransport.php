<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches audit logs for async database persistence via Symfony Messenger.
 *
 * The message is consumed by PersistAuditLogHandler, which hydrates
 * and persists the AuditLog entity in a separate worker process.
 */
final class AsyncDatabaseAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly AuditLogMessageFactory $messageFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function send(AuditLog $log, array $context = []): void
    {
        $message = $this->messageFactory->createPersistMessage($log, $context);

        $this->bus->dispatch($message);
    }

    #[Override]
    public function supports(string $phase, array $context = []): bool
    {
        return $phase === 'post_flush' || $phase === 'post_load';
    }
}
