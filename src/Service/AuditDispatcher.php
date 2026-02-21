<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function sprintf;

final class AuditDispatcher implements AuditDispatcherInterface
{
    public function __construct(
        private readonly AuditTransportInterface $transport,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?AuditIntegrityServiceInterface $integrityService = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $failOnTransportError = false,
        private readonly bool $fallbackToDatabase = true,
    ) {
    }

    public function dispatch(
        AuditLog $audit,
        EntityManagerInterface $em,
        string $phase,
        ?UnitOfWork $uow = null,
    ): bool {
        if (!$this->transport->supports($phase)) {
            return false;
        }

        $this->eventDispatcher?->dispatch(new AuditLogCreatedEvent($audit));

        if ($this->integrityService?->isEnabled() === true) {
            $audit->signature = $this->integrityService->generateSignature($audit);
        }

        $context = [
            'phase' => $phase,
            'em' => $em,
        ];

        if ($uow !== null) {
            $context['uow'] = $uow;
        }

        try {
            $this->transport->send($audit, $context);
            $audit->seal();
        } catch (Throwable $e) {
            $this->logger?->error(
                sprintf('Audit transport failed for %s#%s: %s', $audit->entityClass, $audit->entityId, $e->getMessage()),
                ['exception' => $e]
            );

            if ($this->failOnTransportError) {
                throw $e;
            }

            if ($this->fallbackToDatabase) {
                $this->persistFallback($audit, $em);

                return true;
            }

            return false;
        }

        return true;
    }

    private function persistFallback(AuditLog $audit, EntityManagerInterface $em): void
    {
        try {
            if (!$em->contains($audit)) {
                $em->persist($audit);
            }
            $em->flush();
            $audit->seal();

            $this->logger?->warning(
                sprintf('Audit log for %s#%s saved via database fallback.', $audit->entityClass, $audit->entityId),
            );
        } catch (Throwable $fallbackError) {
            $this->logger?->critical(
                sprintf('AUDIT LOSS: Failed to persist fallback for %s#%s: %s', $audit->entityClass, $audit->entityId, $fallbackError->getMessage()),
                ['exception' => $fallbackError],
            );
        }
    }
}
