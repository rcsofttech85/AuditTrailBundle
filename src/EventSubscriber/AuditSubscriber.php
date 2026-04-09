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
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function count;
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
        private readonly AssociationImpactAnalyzer $associationImpactAnalyzer,
        private readonly TransactionIdGenerator $transactionIdGenerator,
        private readonly AuditAccessHandlerInterface $accessHandler,
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
            $deletedAssociationImpacts = $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($em, $uow);

            // rely on the parent flush and computeChangeSet() in the
            // transport to persist audits, avoiding nested flushes in onFlush.
            $this->entityProcessor->processInsertions($em, $uow);
            $this->entityProcessor->processUpdates($em, $uow, $deletedAssociationImpacts);
            $this->entityProcessor->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionUpdates());
            $this->entityProcessor->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionDeletions());
            $this->entityProcessor->processDeletions($em, $uow, $deletedAssociationImpacts);
        } finally {
            $this->onFlushProcessing = false;
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        // Skip postLoad-driven access auditing while we are already inside
        // flush/postFlush processing to avoid re-entrant audit work caused by
        // Doctrine hydration during the audit dispatch lifecycle itself.
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
            $failedPendingDeletions = $this->processPendingDeletions($em);
            $failedScheduledAudits = $this->processScheduledAudits($em);

            $this->auditManager->clear();
            $this->retainFailedDispatches($failedPendingDeletions, $failedScheduledAudits);
            if ($failedPendingDeletions !== [] || $failedScheduledAudits !== []) {
                $this->logger?->warning('Audit delivery deferred until a later flush.', [
                    'pending_deletions' => count($failedPendingDeletions),
                    'scheduled_audits' => count($failedScheduledAudits),
                ]);
            }

            $this->transactionIdGenerator->reset();
        } finally {
            --$this->postFlushDepth;
        }
    }

    /**
     * @return list<array{entity: object, data: array<string, mixed>, is_managed: bool}>
     */
    private function processPendingDeletions(EntityManagerInterface $em): array
    {
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
                ? $this->auditService->getEntityData($entity, [], $em)
                : null;
            $audit = $this->auditService->createAuditLog($entity, $action, $oldData, $newData, [], $em);

            if (!$this->dispatcher->dispatch($audit, $em, AuditPhase::PostFlush, null, $entity)) {
                $failedPendingDeletions[] = $pending;
                continue;
            }

            $this->markAsAudited($entity, $em);
        }

        return $failedPendingDeletions;
    }

    /**
     * @return array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}>
     */
    private function processScheduledAudits(EntityManagerInterface $em): array
    {
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

            if (!$this->dispatcher->dispatch($audit, $em, AuditPhase::PostFlush, null, $entity)) {
                $failedScheduledAudits[] = $scheduled;
                continue;
            }

            $this->markAsAudited($entity, $em);
        }

        return $failedScheduledAudits;
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
