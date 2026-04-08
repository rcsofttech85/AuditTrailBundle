<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function sprintf;

final class AuditDispatcher implements AuditDispatcherInterface
{
    public function __construct(
        private readonly AuditTransportInterface $transport,
        private readonly AuditLogContextProcessor $contextProcessor,
        private readonly AuditLogWriterInterface $auditLogWriter,
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

        if ($this->integrityService?->isEnabled() === true) {
            $audit->signature = $this->integrityService->generateSignature($audit);
        }

        try {
            $this->transport->send($context);
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
                return $this->persistFallback($audit, $em, $phase, $uow);
            }

            return false;
        }

        return true;
    }

    private function persistFallback(
        AuditLog $audit,
        EntityManagerInterface $em,
        AuditPhase $phase,
        ?UnitOfWork $uow = null,
    ): bool {
        try {
            return match (true) {
                $phase->isOnFlush() => $this->persistOnFlushFallback($audit, $em, $uow),
                $phase->isDeferredPersistencePhase() => $this->persistDeferredFallback($audit, $em),
                default => $this->deferFallbackWithoutImplicitFlush($audit, $em, $phase),
            };
        } catch (Throwable $fallbackError) {
            $this->logger?->critical(
                sprintf('AUDIT LOSS: Failed to persist fallback for %s#%s: %s', $audit->entityClass, $audit->entityId, $fallbackError->getMessage()),
                ['exception' => $fallbackError],
            );

            return false;
        }
    }

    private function persistOnFlushFallback(
        AuditLog $audit,
        EntityManagerInterface $em,
        ?UnitOfWork $uow,
    ): bool {
        if (!$em->contains($audit)) {
            $em->persist($audit);
        }

        if ($uow === null) {
            throw new LogicException('UnitOfWork is required to persist fallback audit logs during on_flush.');
        }

        $uow->computeChangeSet($em->getClassMetadata(AuditLog::class), $audit);

        return $this->finalizeDeferredFallback($audit);
    }

    private function persistDeferredFallback(AuditLog $audit, EntityManagerInterface $em): bool
    {
        $this->auditLogWriter->insert($audit, $em);

        return $this->finalizeDeferredFallback($audit);
    }

    private function deferFallbackWithoutImplicitFlush(
        AuditLog $audit,
        EntityManagerInterface $em,
        AuditPhase $phase,
    ): bool {
        if (!$em->contains($audit)) {
            $em->persist($audit);
        }

        $audit->seal();

        $this->logger?->warning(
            sprintf(
                'Audit log for %s#%s queued via database fallback during phase "%s" without an implicit flush. Persist it with the next application flush.',
                $audit->entityClass,
                $audit->entityId,
                $phase->value,
            ),
        );

        return true;
    }

    private function finalizeDeferredFallback(AuditLog $audit): bool
    {
        $audit->seal();

        $this->logger?->warning(
            sprintf('Audit log for %s#%s saved via database fallback.', $audit->entityClass, $audit->entityId),
        );

        return true;
    }
}
