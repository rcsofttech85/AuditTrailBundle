<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
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
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->isFlushing || $this->recursionDepth > 0) {
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
            $this->processCollectionUpdates($em, $uow->getScheduledCollectionUpdates());
            $this->processDeletions($em, $uow);
        } finally {
            --$this->recursionDepth;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $failedAudits = [];
            $hasNewAudits = false;

            // Process deferred deletions
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
                $hasNewAudits = true;

                if (!$this->safeSendToTransport($audit, ['phase' => 'post_flush', 'em' => $em])) {
                    $failedAudits[] = $audit;
                }
            }
            $this->pendingDeletions = [];

            // Process scheduled audits with ID resolution for inserts
            foreach ($this->scheduledAudits as $scheduled) {
                if ($scheduled['is_insert']) {
                    $id = $this->auditService->getEntityId($scheduled['entity']);
                    if ('pending' !== $id) {
                        $scheduled['audit']->setEntityId($id);
                    }
                }

                if (
                    !$this->safeSendToTransport($scheduled['audit'], [
                        'phase' => 'post_flush',
                        'em' => $em,
                        'is_insert' => $scheduled['is_insert'],
                    ])
                ) {
                    $failedAudits[] = $scheduled['audit'];
                }
            }
            $this->scheduledAudits = [];

            // Handle failed audits with database fallback
            if ([] !== $failedAudits && $this->fallbackToDatabase) {
                foreach ($failedAudits as $audit) {
                    if (!$em->contains($audit)) {
                        $em->persist($audit);
                        $hasNewAudits = true;
                    }
                }
            }

            $this->flushNewAuditsIfNeeded($em, $hasNewAudits, $failedAudits);
        } finally {
            --$this->recursionDepth;
        }
    }

    public function onClear(): void
    {
        $discardedAudits = \count($this->scheduledAudits);
        $discardedDeletions = \count($this->pendingDeletions);

        if ($discardedAudits > 0 || $discardedDeletions > 0) {
            $this->logger?->warning('EntityManager cleared, discarding pending audits', [
                'scheduled_audits' => $discardedAudits,
                'pending_deletions' => $discardedDeletions,
            ]);
        }

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

        $this->logger?->warning('Auto-flushing audits due to batch size threshold', [
            'count' => \count($this->scheduledAudits),
            'threshold' => self::BATCH_FLUSH_THRESHOLD,
        ]);

        $this->isFlushing = true;

        try {
            $this->processScheduledAudits($em);
        } finally {
            $this->isFlushing = false;
        }
    }

    private function processInsertions(EntityManagerInterface $em, \Doctrine\ORM\UnitOfWork $uow): void
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

            $this->scheduleAudit($entity, $audit, isInsert: true);
        }
    }

    private function processUpdates(EntityManagerInterface $em, \Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
            $changeSet = $uow->getEntityChangeSet($entity);
            [$old, $new] = $this->extractChanges($changeSet);

            if ([] === $old && [] === $new) {
                continue;
            }

            $action = $this->determineUpdateAction($changeSet);
            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new);

            if ($this->deferTransportUntilCommit) {
                $this->scheduleAudit($entity, $audit, isInsert: false);
            } else {
                $this->sendOrFallback($audit, $em, 'on_flush');
            }
        }
    }

    /**
     * @param iterable<PersistentCollection<int, object>> $collectionUpdates
     */
    private function processCollectionUpdates(EntityManagerInterface $em, iterable $collectionUpdates): void
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

            /** @var list<object> $insertItems */
            $insertItems = array_values($insertDiff);
            /** @var list<object> $deleteItems */
            $deleteItems = array_values($deleteDiff);
            $newIds = $this->computeNewIds($oldIds, $insertItems, $deleteItems);

            $audit = $this->auditService->createAuditLog(
                $owner,
                AuditLog::ACTION_UPDATE,
                [$fieldName => $oldIds],
                [$fieldName => $newIds]
            );

            if ($this->deferTransportUntilCommit) {
                $this->scheduleAudit($owner, $audit, isInsert: false);
            } else {
                $this->sendOrFallback($audit, $em, 'on_flush');
            }
        }
    }

    private function processDeletions(EntityManagerInterface $em, \Doctrine\ORM\UnitOfWork $uow): void
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

        $this->scheduledAudits[] = [
            'entity' => $entity,
            'audit' => $audit,
            'is_insert' => $isInsert,
        ];
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
    private function extractChanges(array $changeSet): array
    {
        $old = [];
        $new = [];

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if ($oldValue === $newValue) {
                continue;
            }
            $old[$field] = $oldValue;
            $new[$field] = $newValue;
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
            $this->sendOrFallback($scheduled['audit'], $em, 'batch_flush');
            unset($this->scheduledAudits[$i]);
        }

        $this->scheduledAudits = [];
    }

    private function sendOrFallback(AuditLog $audit, EntityManagerInterface $em, string $phase): void
    {
        if (!$this->safeSendToTransport($audit, ['phase' => $phase, 'em' => $em])) {
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
                'entity_class' => $audit->getEntityClass(),
                'entity_id' => $audit->getEntityId(),
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
                'audit_action' => $audit->getAction(),
                'entity_class' => $audit->getEntityClass(),
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

            if ([] !== $failedAudits) {
                $this->logger?->info('Persisted {count} audits to database fallback', [
                    'count' => \count($failedAudits),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to flush fallback audits to database', [
                'exception' => $e->getMessage(),
                'count' => \count($failedAudits),
            ]);

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

        if (!$this->enableHardDelete) {
            return null;
        }

        return AuditLog::ACTION_DELETE;
    }
}
