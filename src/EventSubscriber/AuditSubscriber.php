<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function sprintf;

#[AsDoctrineListener(event: Events::onFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    private bool $isFlushing = false;

    private int $recursionDepth = 0;

    public function __construct(
        private readonly AuditServiceInterface $auditService,
        private readonly ChangeProcessorInterface $changeProcessor,
        private readonly AuditDispatcherInterface $dispatcher,
        private readonly ScheduledAuditManagerInterface $auditManager,
        private readonly EntityProcessorInterface $entityProcessor,
        private readonly TransactionIdGenerator $transactionIdGenerator,
        private readonly AuditAccessHandler $accessHandler,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enableHardDelete = true,
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

            // rely on the parent flush and computeChangeSet() in the
            // transport to persist audits, avoiding nested flushes in onFlush.
            $this->entityProcessor->processInsertions($em, $uow);
            $this->entityProcessor->processUpdates($em, $uow);
            $this->entityProcessor->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionUpdates());
            $this->entityProcessor->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionDeletions());
            $this->entityProcessor->processDeletions($em, $uow);
        } finally {
            --$this->recursionDepth;
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->accessHandler->handleAccess($args->getObject(), $args->getObjectManager());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enabled || $this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $hasDeletions = $this->processPendingDeletions($em);
            $hasScheduled = $this->processScheduledAudits($em);

            $this->auditManager->clear();
            $this->transactionIdGenerator->reset();

            if ($hasDeletions || $hasScheduled) {
                $this->flushNewAuditsIfNeeded($em, true);
            }
        } finally {
            --$this->recursionDepth;
        }
    }

    private function processPendingDeletions(EntityManagerInterface $em): bool
    {
        $hasNewAudits = false;
        // @phpstan-ignore-next-line
        foreach ($this->auditManager->pendingDeletions as $pending) {
            $action = $this->changeProcessor->determineDeletionAction($em, $pending['entity'], $this->enableHardDelete);
            if ($action === null) {
                continue;
            }

            $newData = $action === AuditLogInterface::ACTION_SOFT_DELETE
                ? $this->auditService->getEntityData($pending['entity'])
                : null;
            $audit = $this->auditService->createAuditLog($pending['entity'], $action, $pending['data'], $newData);

            $this->dispatcher->dispatch($audit, $em, 'post_flush');

            $this->markAsAudited($pending['entity'], $em);

            if ($em->contains($audit)) {
                $hasNewAudits = true;
            }
        }

        return $hasNewAudits;
    }

    private function processScheduledAudits(EntityManagerInterface $em): bool
    {
        $hasNewAudits = false;
        // @phpstan-ignore-next-line
        foreach ($this->auditManager->scheduledAudits as $scheduled) {
            if ($scheduled['is_insert']) {
                $id = $this->idResolver->resolveFromEntity($scheduled['entity'], $em);
                if ($id !== AuditLogInterface::PENDING_ID) {
                    $scheduled['audit']->entityId = $id;
                }
            }

            $this->dispatcher->dispatch($scheduled['audit'], $em, 'post_flush');

            $this->markAsAudited($scheduled['entity'], $em);

            if ($em->contains($scheduled['audit'])) {
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
        $this->transactionIdGenerator->reset();
        $this->isFlushing = false;
        $this->recursionDepth = 0;
        $this->accessHandler->reset();
    }

    private function markAsAudited(object $entity, EntityManagerInterface $em): void
    {
        $id = $this->idResolver->resolveFromEntity($entity, $em);
        if ($id !== AuditLogInterface::PENDING_ID) {
            $this->accessHandler->markAsAudited(sprintf('%s:%s', $entity::class, $id));
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
        } catch (Throwable $e) {
            $this->logger?->critical('Failed to flush audits', ['exception' => $e->getMessage()]);
        } finally {
            $this->isFlushing = false;
        }
    }
}
