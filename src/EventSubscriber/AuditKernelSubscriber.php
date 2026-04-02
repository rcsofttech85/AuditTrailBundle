<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function count;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class AuditKernelSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditAccessHandler $accessHandler,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if ($this->accessHandler->hasPendingAccesses()) {
            $this->accessHandler->flushPendingAccesses();
        }

        if (!$this->entityManager->isOpen()) {
            return;
        }

        $uow = $this->entityManager->getUnitOfWork();
        $uow->computeChangeSets();

        $scheduledInsertions = $uow->getScheduledEntityInsertions();
        $scheduledUpdates = $uow->getScheduledEntityUpdates();
        $scheduledDeletions = $uow->getScheduledEntityDeletions();

        // If there are updates or deletions, it's unsafe to flush
        if ($scheduledUpdates !== [] || $scheduledDeletions !== []) {
            return;
        }

        // If there are no insertions, nothing to do
        if ($scheduledInsertions === []) {
            return;
        }

        // Check if all insertions are AuditLogs
        if (!array_all($scheduledInsertions, static fn (object $entity) => $entity instanceof AuditLog)) {
            return;
        }

        // Safe to flush
        try {
            $this->entityManager->flush();
        } catch (Throwable $e) {
            $this->logger?->critical('Failed to flush deferred audit logs during kernel terminate.', [
                'scheduled_insertions' => count($scheduledInsertions),
                'exception' => $e,
            ]);
        }
    }
}
