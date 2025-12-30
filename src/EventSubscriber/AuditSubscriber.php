<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Symfony\Contracts\Service\ResetInterface;

#[AsDoctrineListener(event: Events::onFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    private const int BATCH_FLUSH_THRESHOLD = 500;

    private bool $isFlushing = false;
    private int $recursionDepth = 0;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly ChangeProcessor $changeProcessor,
        private readonly AuditDispatcher $dispatcher,
        private readonly ScheduledAuditManager $auditManager,
        private readonly EntityProcessor $entityProcessor,
        private readonly bool $enableHardDelete = true,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled || $this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            $uow->computeChangeSets();

            $this->handleBatchFlushIfNeeded($em);
            $this->entityProcessor->processInsertions($em, $uow);
            $this->entityProcessor->processUpdates($em, $uow);
            $this->entityProcessor->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionUpdates());
            $this->entityProcessor->processDeletions($em, $uow);
        } finally {
            --$this->recursionDepth;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enabled || $this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $hasNewAudits = $this->processPendingDeletions($em);
            $hasNewAudits = $this->processScheduledAudits($em) || $hasNewAudits;

            $this->auditManager->clear();
            $this->flushNewAuditsIfNeeded($em, $hasNewAudits);
        } finally {
            --$this->recursionDepth;
        }
    }

    private function processPendingDeletions(EntityManagerInterface $em): bool
    {
        $hasNewAudits = false;
        foreach ($this->auditManager->getPendingDeletions() as $pending) {
            $action = $this->changeProcessor->determineDeletionAction($em, $pending['entity'], $this->enableHardDelete);
            if (null === $action) {
                continue;
            }

            $newData = AuditLog::ACTION_SOFT_DELETE === $action ? $this->auditService->getEntityData($pending['entity']) : null;
            $audit = $this->auditService->createAuditLog($pending['entity'], $action, $pending['data'], $newData);

            $em->persist($audit);
            $hasNewAudits = true;
            $this->dispatcher->dispatch($audit, $em, 'post_flush');
        }

        return $hasNewAudits;
    }

    private function processScheduledAudits(EntityManagerInterface $em): bool
    {
        $hasNewAudits = false;
        foreach ($this->auditManager->getScheduledAudits() as $scheduled) {
            if ($scheduled['is_insert']) {
                $id = $this->auditService->getEntityId($scheduled['entity']);
                if ('pending' !== $id) {
                    $scheduled['audit']->setEntityId($id);
                }
            }

            if ($this->dispatcher->dispatch($scheduled['audit'], $em, 'post_flush')) {
                $hasNewAudits = true;
            }
        }

        return $hasNewAudits;
    }

    public function onClear(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->auditManager->clear();
        $this->isFlushing = false;
        $this->recursionDepth = 0;
    }

    private function handleBatchFlushIfNeeded(EntityManagerInterface $em): void
    {
        if ($this->auditManager->countScheduled() < self::BATCH_FLUSH_THRESHOLD) {
            return;
        }

        $this->isFlushing = true;
        try {
            foreach ($this->auditManager->getScheduledAudits() as $scheduled) {
                $this->dispatcher->dispatch($scheduled['audit'], $em, 'batch_flush');
            }
            $this->auditManager->clear();
        } finally {
            $this->isFlushing = false;
        }
    }

    private function flushNewAuditsIfNeeded(EntityManagerInterface $em, bool $hasNewAudits): void
    {
        if (!$hasNewAudits) {
            return;
        }

        $this->isFlushing = true;
        try {
            $em->flush();
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to flush audits', ['exception' => $e->getMessage()]);
        } finally {
            $this->isFlushing = false;
        }
    }
}
