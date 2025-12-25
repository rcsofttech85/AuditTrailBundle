<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

#[AsDoctrineListener(event: Events::onFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    private const int MAX_SCHEDULED_AUDITS = 1000;
    private const int BATCH_FLUSH_THRESHOLD = 500;

    /** @var array<int, array{entity: object, audit: AuditLog, is_insert: bool}> */
    private array $scheduledAudits = [];

    /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool}> */
    private array $pendingDeletions = [];

    private bool $isFlushing = false;
    private int $recursionDepth = 0;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly AuditTransportInterface $transport,
        private readonly bool $enableSoftDelete = true,
        private readonly bool $enableHardDelete = true,
        private readonly string $softDeleteField = 'deletedAt',
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $failOnTransportError = false,
        private readonly bool $deferTransportUntilCommit = true,
        private readonly bool $fallbackToDatabase = true,
        private readonly bool $enabled = true,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
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
            $this->processInsertions($em, $uow);
            $this->processUpdates($em, $uow);
            // Fix: Pass UnitOfWork to collection updates
            $this->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionUpdates());
            $this->processDeletions($em, $uow);
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
            $failedAudits = [];
            $hasNewAudits = false;

            // 1. Process deferred deletions
            foreach ($this->pendingDeletions as $pending) {
                $action = $this->determineDeletionAction($em, $pending['entity'], $pending['is_managed']);

                if (null === $action) {
                    continue;
                }

                $newData = AuditLog::ACTION_SOFT_DELETE === $action
                    ? $this->auditService->getEntityData($pending['entity'])
                    : null;

                $audit = $this->auditService->createAuditLog(
                    $pending['entity'],
                    $action,
                    $pending['data'],
                    $newData
                );

                $em->persist($audit);
                $hasNewAudits = true; // Deletions always result in a new persist here

                if (!$this->safeSendToTransport($audit, ['phase' => 'post_flush', 'em' => $em])) {
                    $failedAudits[] = $audit;
                }
            }
            $this->pendingDeletions = [];

            // 2. Process scheduled audits (Inserts/Updates)
            foreach ($this->scheduledAudits as $scheduled) {
                if ($scheduled['is_insert']) {
                    $id = $this->auditService->getEntityId($scheduled['entity']);
                    if ('pending' !== $id) {
                        $scheduled['audit']->setEntityId($id);
                    }
                }

                $sent = $this->safeSendToTransport($scheduled['audit'], [
                    'phase' => 'post_flush',
                    'em' => $em,
                    'is_insert' => $scheduled['is_insert'],
                ]);

                if ($sent) {
                    // Fix: If transport succeeded (e.g. Doctrine transport), it might have persisted the entity.
                    // We mark this true so flushNewAuditsIfNeeded runs at the end to commit these persists.
                    $hasNewAudits = true;
                } else {
                    $failedAudits[] = $scheduled['audit'];
                }
            }
            $this->scheduledAudits = [];

            // 3. Handle failed audits fallback
            if ([] !== $failedAudits && $this->fallbackToDatabase) {
                foreach ($failedAudits as $audit) {
                    if (!$em->contains($audit)) {
                        $em->persist($audit);
                        $hasNewAudits = true;
                    }
                }
            }

            // 4. Final Batch Flush
            $this->flushNewAuditsIfNeeded($em, $hasNewAudits, $failedAudits);
        } finally {
            --$this->recursionDepth;
        }
    }

    public function onClear(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
        $this->isFlushing = false;
        $this->recursionDepth = 0;
    }

    private function handleBatchFlushIfNeeded(EntityManagerInterface $em): void
    {
        if (\count($this->scheduledAudits) < self::BATCH_FLUSH_THRESHOLD) {
            return;
        }

        $this->isFlushing = true;
        try {
            $this->processScheduledAudits($em);
        } finally {
            $this->isFlushing = false;
        }
    }

    private function processInsertions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLog::ACTION_CREATE,
                null,
                $this->auditService->getEntityData($entity)
            );

            $sentOnFlush = false;

            if (!$this->deferTransportUntilCommit && $this->transport->supports('on_flush')) {
                // For insertions, we might not have an ID yet if it's auto-increment and not flushed.
                // However, DoctrineTransport handles this by computing changeset.
                $this->sendOrFallback($audit, $em, 'on_flush', $uow);
                $sentOnFlush = true;
            }

            if (!$sentOnFlush || $this->transport->supports('post_flush')) {
                $this->scheduleAudit($entity, $audit, isInsert: true);
            }
        }
    }

    private function processUpdates(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
            $changeSet = $uow->getEntityChangeSet($entity);
            [$old, $new] = $this->extractChanges($entity, $changeSet);

            if ([] === $old && [] === $new) {
                continue;
            }

            $action = $this->determineUpdateAction($changeSet);
            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new);

            $sentOnFlush = false;

            // 1. Try to send immediately (e.g. DoctrineTransport)
            if (!$this->deferTransportUntilCommit && $this->transport->supports('on_flush')) {
                $this->sendOrFallback($audit, $em, 'on_flush', $uow);
                $sentOnFlush = true;
            }

            // 2. Schedule for post_flush if needed (e.g. HttpTransport in a Chain)
            // If we didn't send on flush, OR if the transport ALSO supports post_flush (Chain), we schedule.
            if (!$sentOnFlush || $this->transport->supports('post_flush')) {
                $this->scheduleAudit($entity, $audit, isInsert: false);
            }
        }
    }

    /**
     * @param iterable<PersistentCollection<int, object>> $collectionUpdates
     */
    private function processCollectionUpdates(EntityManagerInterface $em, UnitOfWork $uow, iterable $collectionUpdates): void
    {
        foreach ($collectionUpdates as $collection) {
            $owner = $collection->getOwner();

            if (null === $owner || !$this->shouldProcessEntity($owner)) {
                continue;
            }

            $insertDiff = $collection->getInsertDiff();
            $deleteDiff = $collection->getDeleteDiff();

            if ([] === $insertDiff && [] === $deleteDiff) {
                continue;
            }

            $mapping = $collection->getMapping();
            /** @var string $fieldName */
            $fieldName = $mapping['fieldName'];

            /** @var array<int, object> $snapshot */
            $snapshot = $collection->getSnapshot();
            $oldIds = $this->extractIdsFromCollection($snapshot);

            $insertItems = array_values($insertDiff);
            $deleteItems = array_values($deleteDiff);
            $newIds = $this->computeNewIds($oldIds, $insertItems, $deleteItems);

            $audit = $this->auditService->createAuditLog(
                $owner,
                AuditLog::ACTION_UPDATE,
                [$fieldName => $oldIds],
                [$fieldName => $newIds]
            );

            $sentOnFlush = false;

            if (!$this->deferTransportUntilCommit && $this->transport->supports('on_flush')) {
                $this->sendOrFallback($audit, $em, 'on_flush', $uow);
                $sentOnFlush = true;
            }

            if (!$sentOnFlush || $this->transport->supports('post_flush')) {
                $this->scheduleAudit($owner, $audit, isInsert: false);
            }
        }
    }

    private function processDeletions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $this->pendingDeletions[] = [
                'entity' => $entity,
                'data' => $this->auditService->getEntityData($entity),
                'is_managed' => $em->contains($entity),
            ];
        }
    }

    private function scheduleAudit(object $entity, AuditLog $audit, bool $isInsert): void
    {
        if (\count($this->scheduledAudits) >= self::MAX_SCHEDULED_AUDITS) {
            throw new \OverflowException(\sprintf('Maximum audit queue size exceeded (%d). Consider batch processing.', self::MAX_SCHEDULED_AUDITS));
        }

        $audit = $this->dispatchAuditCreatedEvent($entity, $audit);

        $this->scheduledAudits[] = [
            'entity' => $entity,
            'audit' => $audit,
            'is_insert' => $isInsert,
        ];
    }

    private function dispatchAuditCreatedEvent(object $entity, AuditLog $audit): AuditLog
    {
        if (null === $this->eventDispatcher) {
            return $audit;
        }

        $event = new AuditLogCreatedEvent($audit, $entity);
        $this->eventDispatcher->dispatch($event, AuditLogCreatedEvent::NAME);

        return $event->getAuditLog();
    }

    private function shouldProcessEntity(object $entity): bool
    {
        return !$entity instanceof AuditLog && $this->auditService->shouldAudit($entity);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractChanges(object $entity, array $changeSet): array
    {
        $old = [];
        $new = [];
        $sensitiveFields = $this->auditService->getSensitiveFields($entity);

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if ($oldValue === $newValue) {
                continue;
            }

            if (isset($sensitiveFields[$field])) {
                $old[$field] = $sensitiveFields[$field];
                $new[$field] = $sensitiveFields[$field];
            } else {
                $old[$field] = $oldValue;
                $new[$field] = $newValue;
            }
        }

        return [$old, $new];
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function determineUpdateAction(array $changeSet): string
    {
        if (!$this->enableSoftDelete || !\array_key_exists($this->softDeleteField, $changeSet)) {
            return AuditLog::ACTION_UPDATE;
        }

        [$oldValue, $newValue] = $changeSet[$this->softDeleteField];

        return (null !== $oldValue && null === $newValue)
            ? AuditLog::ACTION_RESTORE
            : AuditLog::ACTION_UPDATE;
    }

    /**
     * @param array<int, object> $items
     *
     * @return list<int|string>
     */
    private function extractIdsFromCollection(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = $this->extractEntityId($item);
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function extractEntityId(object $entity): int|string|null
    {
        if (!\method_exists($entity, 'getId')) {
            return null;
        }
        $id = $entity->getId();

        return (\is_int($id) || \is_string($id)) ? $id : null;
    }

    /**
     * @param list<int|string> $oldIds
     * @param iterable<object> $insertDiff
     * @param iterable<object> $deleteDiff
     *
     * @return list<int|string>
     */
    private function computeNewIds(array $oldIds, iterable $insertDiff, iterable $deleteDiff): array
    {
        $newIds = $oldIds;
        foreach ($insertDiff as $item) {
            $id = $this->extractEntityId($item);
            if (null !== $id && !\in_array($id, $newIds, true)) {
                $newIds[] = $id;
            }
        }
        foreach ($deleteDiff as $item) {
            $id = $this->extractEntityId($item);
            if (null !== $id) {
                $key = \array_search($id, $newIds, true);
                if (false !== $key) {
                    unset($newIds[$key]);
                }
            }
        }

        return \array_values($newIds);
    }

    private function processScheduledAudits(EntityManagerInterface $em): void
    {
        foreach ($this->scheduledAudits as $i => $scheduled) {
            // Logic for batch flush (rarely used but needs safe fallback)
            // We pass null for UoW here as batch flush happens outside typical UoW calc flow
            $this->sendOrFallback($scheduled['audit'], $em, 'batch_flush', null);
            unset($this->scheduledAudits[$i]);
        }
        $this->scheduledAudits = [];
    }

    // Fix: Add UnitOfWork argument
    private function sendOrFallback(AuditLog $audit, EntityManagerInterface $em, string $phase, ?UnitOfWork $uow = null): void
    {
        $context = [
            'phase' => $phase,
            'em' => $em,
        ];

        if ($uow) {
            $context['uow'] = $uow;
        }

        if (!$this->safeSendToTransport($audit, $context)) {
            $this->persistFallback($audit, $em, $phase);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeSendToTransport(AuditLog $audit, array $context): bool
    {
        try {
            $this->transport->send($audit, $context);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send audit to transport', [
                'exception' => $e->getMessage(),
                'audit_action' => $audit->getAction(),
            ]);

            if ($this->failOnTransportError) {
                throw $e;
            }

            return false;
        }
    }

    private function persistFallback(AuditLog $audit, EntityManagerInterface $em, string $phase): void
    {
        if (!$this->fallbackToDatabase || !$em->isOpen()) {
            return;
        }

        try {
            if ($em->contains($audit)) {
                return;
            }

            $em->persist($audit);

            if ('on_flush' === $phase) {
                $em->getUnitOfWork()->computeChangeSet(
                    $em->getClassMetadata(AuditLog::class),
                    $audit
                );
            }
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to persist audit log to database fallback', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<AuditLog> $failedAudits
     */
    private function flushNewAuditsIfNeeded(EntityManagerInterface $em, bool $hasNewAudits, array $failedAudits): void
    {
        if (!$hasNewAudits) {
            return;
        }

        $this->isFlushing = true;

        try {
            $em->flush();
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to flush audits', ['exception' => $e->getMessage()]);
            if ($this->failOnTransportError) {
                throw $e;
            }
        } finally {
            $this->isFlushing = false;
        }
    }

    private function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $wasManaged): ?string
    {
        if ($this->enableSoftDelete) {
            $meta = $em->getClassMetadata($entity::class);
            if ($meta->hasField($this->softDeleteField)) {
                $reflProp = $meta->getReflectionProperty($this->softDeleteField);
                $softDeleteValue = $reflProp?->getValue($entity);
                if (null !== $softDeleteValue) {
                    return AuditLog::ACTION_SOFT_DELETE;
                }
            }
        }

        return $this->enableHardDelete ? AuditLog::ACTION_DELETE : null;
    }
}
