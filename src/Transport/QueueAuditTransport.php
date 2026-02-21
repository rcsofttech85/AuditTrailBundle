<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use const JSON_THROW_ON_ERROR;

final class QueueAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AuditIntegrityServiceInterface $integrityService,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly ?string $apiKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function send(AuditLog $log, array $context = []): void
    {
        $entityId = $this->idResolver->resolve($log, $context) ?? $log->entityId;

        $message = AuditLogMessage::createFromAuditLog($log, $entityId);

        $event = new AuditMessageStampEvent($message);
        $this->eventDispatcher->dispatch($event);

        if ($event->isPropagationStopped()) {
            return;
        }

        $stamps = $event->getStamps();

        if ($this->apiKey !== null) {
            $stamps[] = new ApiKeyStamp($this->apiKey);
        }

        if ($this->integrityService->isEnabled()) {
            $payload = json_encode($message, JSON_THROW_ON_ERROR);
            $signature = $this->integrityService->signPayload($payload);
            $stamps[] = new SignatureStamp($signature);
        }

        $this->bus->dispatch($message, $stamps);
    }

    #[Override]
    public function supports(string $phase, array $context = []): bool
    {
        return $phase === 'post_flush' || $phase === 'post_load';
    }
}
