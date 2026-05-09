<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogMessageFactoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Rcsofttech\AuditTrailBundle\Serializer\AuditLogMessagePayloadEncoder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class QueueAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private EventDispatcherInterface $eventDispatcher,
        private AuditIntegrityServiceInterface $integrityService,
        private AuditLogMessageFactoryInterface $messageFactory,
        private ?string $apiKey = null,
        private AuditLogMessagePayloadEncoder $payloadEncoder = new AuditLogMessagePayloadEncoder(),
    ) {
    }

    #[Override]
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        $message = $this->messageFactory->createQueueMessage($context);

        $event = new AuditMessageStampEvent($message);
        $this->eventDispatcher->dispatch($event);

        if ($event->isPropagationStopped()) {
            return AuditDeliveryResult::delivered();
        }

        $stamps = $event->getStamps();

        if ($this->apiKey !== null) {
            $stamps[] = new ApiKeyStamp($this->apiKey);
        }

        if ($this->integrityService->isEnabled()) {
            $payload = $this->payloadEncoder->encode($message);
            $signature = $this->integrityService->signPayload($payload);
            $stamps[] = new SignatureStamp($signature);
        }

        $this->bus->dispatch($message, $stamps);

        return AuditDeliveryResult::delivered();
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        return $context->phase->isAsyncDispatchPhase() && $context->audit->hasResolvedEntityId();
    }
}
