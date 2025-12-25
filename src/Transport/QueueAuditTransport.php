<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final class QueueAuditTransport implements AuditTransportInterface
{
    use PendingIdResolver;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLog $log, array $context = []): void
    {

        $entityId = $this->resolveEntityId($log, $context) ?? $log->getEntityId();

        try {
            $message = new AuditLogMessage(
                $log->getEntityClass(),
                $entityId,
                $log->getAction(),
                $log->getOldValues(),
                $log->getNewValues(),
                $log->getUserId(),
                $log->getUsername(),
                $log->getIpAddress(),
                $log->getCreatedAt()
            );

            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch audit log message', [
                'exception' => $e,
                'entity_class' => $log->getEntityClass(),
            ]);
        }
    }

    public function supports(string $phase): bool
    {
        return 'post_flush' === $phase;
    }
}
