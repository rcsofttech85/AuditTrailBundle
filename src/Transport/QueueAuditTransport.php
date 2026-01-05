<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class QueueAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AuditIntegrityServiceInterface $integrityService,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLogInterface $log, array $context = []): void
    {

        $entityId = EntityIdResolver::resolve($log, $context) ?? $log->getEntityId();

        $message = new AuditLogMessage(
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

        $event = new AuditMessageStampEvent($message);
        $this->eventDispatcher->dispatch($event);

        if (!$event->isCancelled()) {
            try {
                $stamps = $event->getStamps();

                if ($this->integrityService->isEnabled()) {
                    // JSON representation of the message to ensure consistency
                    $payload = json_encode($message, JSON_THROW_ON_ERROR);
                    $signature = $this->integrityService->signPayload($payload);
                    $stamps[] = new SignatureStamp($signature);
                }

                $this->bus->dispatch($message, $stamps);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to dispatch audit message to queue', [
                    'entity_class' => $log->getEntityClass(),
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function supports(string $phase, array $context = []): bool
    {
        return 'post_flush' === $phase;
    }
}
