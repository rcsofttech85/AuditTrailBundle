<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Event\AuditDeliveryFailedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function sprintf;

final readonly class AuditFallbackPersister
{
    public function __construct(
        private AuditLogWriterInterface $auditLogWriter,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function persist(
        AuditLog $audit,
        EntityManagerInterface $em,
        AuditPhase $phase,
        ?UnitOfWork $uow = null,
        ?Throwable $transportError = null,
        ?object $entity = null,
    ): bool {
        try {
            return match (true) {
                $phase->isOnFlush() => $this->persistOnFlush($audit, $em, $uow),
                default => $this->persistDeferred($audit, $em, $phase),
            };
        } catch (Throwable $fallbackError) {
            $this->logger?->critical(
                sprintf('AUDIT LOSS: Failed to persist fallback for %s#%s: %s', $audit->entityClass, $audit->entityId, $fallbackError->getMessage()),
                ['exception' => $fallbackError],
            );

            if ($transportError !== null && $this->eventDispatcher !== null) {
                $this->eventDispatcher->dispatch(
                    new AuditDeliveryFailedEvent($audit, $phase, $transportError, $fallbackError, $entity),
                );
            }

            return false;
        }
    }

    private function persistOnFlush(AuditLog $audit, EntityManagerInterface $em, ?UnitOfWork $uow): bool
    {
        if (!$em->contains($audit)) {
            $em->persist($audit);
        }

        if ($uow === null) {
            throw new LogicException('UnitOfWork is required to persist fallback audit logs during on_flush.');
        }

        $uow->computeChangeSet($em->getClassMetadata(AuditLog::class), $audit);

        return $this->finalize($audit);
    }

    private function persistDeferred(AuditLog $audit, EntityManagerInterface $em, ?AuditPhase $phase = null): bool
    {
        $this->auditLogWriter->insert($audit, $em);

        return $this->finalize($audit, $phase);
    }

    private function finalize(AuditLog $audit, ?AuditPhase $phase = null): bool
    {
        $audit->seal();

        $message = $phase === null
            ? sprintf('Audit log for %s#%s saved via database fallback.', $audit->entityClass, $audit->entityId)
            : sprintf(
                'Audit log for %s#%s saved via database fallback during phase "%s".',
                $audit->entityClass,
                $audit->entityId,
                $phase->value,
            );

        $this->logger?->warning($message);

        return true;
    }
}
