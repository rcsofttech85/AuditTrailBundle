<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Event\AuditDeliveryFailedEvent;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function sprintf;

final class AuditDispatcher implements AuditDispatcherInterface
{
    public function __construct(
        private readonly AuditTransportInterface $transport,
        private readonly AuditLogContextProcessor $contextProcessor,
        private readonly AuditFallbackPersister $fallbackPersister,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?AuditIntegrityServiceInterface $integrityService = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $failOnTransportError = false,
        private readonly bool $fallbackToDatabase = true,
    ) {
    }

    #[Override]
    public function dispatch(
        AuditLog $audit,
        EntityManagerInterface $em,
        AuditPhase $phase,
        ?UnitOfWork $uow = null,
        ?object $entity = null,
    ): bool {
        $context = new AuditTransportContext($phase, $em, $audit, $uow, $entity);

        if (!$this->transport->supports($context)) {
            return false;
        }

        if ($this->eventDispatcher !== null) {
            $event = new AuditLogCreatedEvent($audit, $entity);
            $this->eventDispatcher->dispatch($event);
            $audit = $event->auditLog;
        }

        $this->contextProcessor->prepare($audit, $entity, $phase);
        $context = $context->withAudit($audit);
        $this->ensureDeliveryId($audit);

        if ($this->integrityService?->isEnabled() === true) {
            $audit->signature = $this->integrityService->generateSignature($audit);
        }

        try {
            $result = $this->transport->send($context);

            if ($result->isPartial()) {
                $failure = $result->failure ?? new RuntimeException('Audit delivery partially failed.');
                $this->logger?->critical(
                    sprintf(
                        'Audit delivery partially succeeded for %s#%s before failing in a later transport.',
                        $audit->entityClass,
                        $audit->entityId,
                    ),
                    [
                        'completed_transports' => $result->completedTransports,
                        'exception' => $failure,
                    ],
                );

                if ($this->eventDispatcher !== null) {
                    $this->eventDispatcher->dispatch(
                        new AuditDeliveryFailedEvent($audit, $phase, $failure, $failure, $entity),
                    );
                }

                if ($this->failOnTransportError) {
                    throw $failure;
                }
            }

            $audit->seal();

            return true;
        } catch (Throwable $e) {
            $this->logger?->error(
                sprintf('Audit transport failed for %s#%s: %s', $audit->entityClass, $audit->entityId, $e->getMessage()),
                ['exception' => $e]
            );

            if ($this->failOnTransportError) {
                throw $e;
            }

            if ($this->fallbackToDatabase) {
                return $this->fallbackPersister->persist($audit, $em, $phase, $uow, $e, $entity);
            }

            return false;
        }
    }

    private function ensureDeliveryId(AuditLog $audit): void
    {
        if ($audit->deliveryId === null) {
            $audit->deliveryId = Uuid::v7()->toRfc4122();
        }
    }
}
