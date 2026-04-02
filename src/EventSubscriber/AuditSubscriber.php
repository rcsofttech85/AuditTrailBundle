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
use Rcsofttech\AuditTrailBundle\Exception\AuditException;
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
    private bool $onFlushProcessing = false;

    private int $postFlushDepth = 0;

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
        if (!$this->auditManager->isEnabled() || $this->onFlushProcessing || $this->postFlushDepth > 0) {
            return;
        }

        $this->onFlushProcessing = true;

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
            $this->onFlushProcessing = false;
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        if (!$this->auditManager->isEnabled() || $this->onFlushProcessing || $this->postFlushDepth > 0) {
            return;
        }

        $this->accessHandler->handleAccess($args->getObject(), $args->getObjectManager());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->auditManager->isEnabled() || $this->postFlushDepth > 0) {
            return;
        }

        ++$this->postFlushDepth;

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
            --$this->postFlushDepth;
        }
    }

    /**
     * @return array{hasNewAudits: bool, failed: list<array{entity: object, data: array<string, mixed>, is_managed: bool}>}
     */
    private function processPendingDeletions(EntityManagerInterface $em): array
    {
        $hasNewAudits = false;
        /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $failedPendingDeletions */
        $failedPendingDeletions = [];
        /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $pendingDeletions */
        $pendingDeletions = $this->auditManager->getPendingDeletions();
        foreach ($pendingDeletions as $pending) {
            $entity = $pending['entity'];
            $oldData = $pending['data'];

            $action = $this->changeProcessor->determineDeletionAction($em, $entity, $this->enableHardDelete);
            if ($action === null) {
                continue;
            }

            $newData = $action === AuditLogInterface::ACTION_SOFT_DELETE
                ? $this->auditService->getEntityData($entity)
                : null;
            $audit = $this->auditService->createAuditLog($entity, $action, $oldData, $newData);

            if (!$this->dispatcher->dispatch($audit, $em, 'post_flush', null, $entity)) {
                $failedPendingDeletions[] = $pending;
                continue;
            }

            $this->markAsAudited($entity, $em);

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
        /** @var array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}> $failedScheduledAudits */
        $failedScheduledAudits = [];
        /** @var array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}> $scheduledAudits */
        $scheduledAudits = $this->auditManager->getScheduledAudits();
        foreach ($scheduledAudits as $scheduled) {
            $entity = $scheduled['entity'];
            $audit = $scheduled['audit'];

            if ($scheduled['is_insert']) {
                $id = $this->idResolver->resolveFromEntity($entity, $em);
                if ($id !== AuditLogInterface::PENDING_ID) {
                    $audit->entityId = $id;
                }
            }

            if (!$this->dispatcher->dispatch($audit, $em, 'post_flush', null, $entity)) {
                $failedScheduledAudits[] = $scheduled;
                continue;
            }

            $this->markAsAudited($entity, $em);

            if ($em->contains($audit)) {
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
        $this->onFlushProcessing = false;
        $this->postFlushDepth = 0;
        $this->accessHandler->reset();
    }

    private function markAsAudited(object $entity, EntityManagerInterface $em): void
    {
        $id = $this->idResolver->resolveFromEntity($entity, $em);
        if ($id !== AuditLogInterface::PENDING_ID) {
            try {
                $class = $em->getClassMetadata($entity::class)->getName();
            } catch (Throwable) {
                $class = $entity::class;
            }

            $this->accessHandler->markAsAudited(sprintf('%s:%s', $class, $id));
        }
    }

    private function flushNewAuditsIfNeeded(EntityManagerInterface $em, bool $hasNewAudits): void
    {
        if (!$hasNewAudits) {
            return;
        }

        try {
            $em->flush();
        } catch (Throwable $e) {
            $this->logger?->critical('Failed to flush audits', ['exception' => $e->getMessage()]);

            if (!$em->isOpen()) {
                throw new AuditException('Database flush failed during audit logging, destroying the EntityManager. Original error: '.$e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * @param list<array{entity: object, data: array<string, mixed>, is_managed: bool}>                               $failedPendingDeletions
     * @param array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}> $failedScheduledAudits
     */
    private function retainFailedDispatches(array $failedPendingDeletions, array $failedScheduledAudits): void
    {
        if ($failedPendingDeletions !== []) {
            $this->auditManager->replacePendingDeletions($failedPendingDeletions);
        }

        if ($failedScheduledAudits !== []) {
            $this->auditManager->replaceScheduledAudits($failedScheduledAudits);
        }
    }
}
