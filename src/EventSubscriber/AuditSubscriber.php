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
use function trigger_deprecation;

#[AsDoctrineListener(event: Events::onFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    private static bool $pendingDeletionRetentionDeprecationTriggered = false;

    private static bool $scheduledAuditRetentionDeprecationTriggered = false;

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
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->auditManager->isEnabled();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->auditManager->isEnabled() || $this->isFlushing || $this->recursionDepth > 0) {
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
        if (!$this->auditManager->isEnabled()) {
            return;
        }

        $this->accessHandler->handleAccess($args->getObject(), $args->getObjectManager());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->auditManager->isEnabled() || $this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $deletionResult = $this->processPendingDeletions($em);
            $scheduledResult = $this->processScheduledAudits($em);

            $this->auditManager->clear();
            $this->retainFailedDispatches($deletionResult['failed'], $scheduledResult['failed']);
            $this->transactionIdGenerator->reset();

            if ($deletionResult['hasNewAudits'] || $scheduledResult['hasNewAudits']) {
                $this->flushNewAuditsIfNeeded($em, true);
            }
        } finally {
            --$this->recursionDepth;
        }
    }

    /**
     * @return array{hasNewAudits: bool, failed: list<array{entity: object, data: array<string, mixed>, is_managed: bool}>}
     */
    private function processPendingDeletions(EntityManagerInterface $em): array
    {
        $hasNewAudits = false;
        $failedPendingDeletions = [];
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

            if (!$this->dispatcher->dispatch($audit, $em, 'post_flush')) {
                $failedPendingDeletions[] = $pending;
                continue;
            }

            $this->markAsAudited($pending['entity'], $em);

            if ($em->contains($audit)) {
                $hasNewAudits = true;
            }
        }

        return [
            'hasNewAudits' => $hasNewAudits,
            'failed' => $failedPendingDeletions,
        ];
    }

    /**
     * @return array{hasNewAudits: bool, failed: array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}>}
     */
    private function processScheduledAudits(EntityManagerInterface $em): array
    {
        $hasNewAudits = false;
        $failedScheduledAudits = [];
        // @phpstan-ignore-next-line
        foreach ($this->auditManager->scheduledAudits as $scheduled) {
            if ($scheduled['is_insert']) {
                $id = $this->idResolver->resolveFromEntity($scheduled['entity'], $em);
                if ($id !== AuditLogInterface::PENDING_ID) {
                    $scheduled['audit']->entityId = $id;
                }
            }

            if (!$this->dispatcher->dispatch($scheduled['audit'], $em, 'post_flush')) {
                $failedScheduledAudits[] = $scheduled;
                continue;
            }

            $this->markAsAudited($scheduled['entity'], $em);

            if ($em->contains($scheduled['audit'])) {
                $hasNewAudits = true;
            }
        }

        return [
            'hasNewAudits' => $hasNewAudits,
            'failed' => $failedScheduledAudits,
        ];
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

    /**
     * @param list<array{entity: object, data: array<string, mixed>, is_managed: bool}>                               $failedPendingDeletions
     * @param array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}> $failedScheduledAudits
     */
    private function retainFailedDispatches(array $failedPendingDeletions, array $failedScheduledAudits): void
    {
        if ($failedPendingDeletions !== []) {
            if (method_exists($this->auditManager, 'replacePendingDeletions')) {
                $this->auditManager->replacePendingDeletions($failedPendingDeletions);
            } elseif (!self::$pendingDeletionRetentionDeprecationTriggered) {
                self::$pendingDeletionRetentionDeprecationTriggered = true;
                trigger_deprecation(
                    'rcsofttech/audit-trail-bundle',
                    '2.3.3',
                    'Omitting "%s::replacePendingDeletions()" from custom scheduled audit managers is deprecated because failed post_flush deletions can no longer be retained for retry. Implement this method; it will be required in a future major release.',
                    $this->auditManager::class
                );
            }
        }

        if ($failedScheduledAudits !== []) {
            if (method_exists($this->auditManager, 'replaceScheduledAudits')) {
                $this->auditManager->replaceScheduledAudits($failedScheduledAudits);
            } elseif (!self::$scheduledAuditRetentionDeprecationTriggered) {
                self::$scheduledAuditRetentionDeprecationTriggered = true;
                trigger_deprecation(
                    'rcsofttech/audit-trail-bundle',
                    '2.3.3',
                    'Omitting "%s::replaceScheduledAudits()" from custom scheduled audit managers is deprecated because failed post_flush audits can no longer be retained for retry. Implement this method; it will be required in a future major release.',
                    $this->auditManager::class
                );
            }
        }
    }
}
