<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class AuditKernelSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
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
        } catch (Throwable) {
            // Ignore flush errors during terminate to avoid crashing the process
        }
    }
}
