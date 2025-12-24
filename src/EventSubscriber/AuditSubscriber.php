<?php

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
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

    /**
     * @var array<array{entity: object, audit: AuditLog, is_insert?: bool}>
     */
    private array $scheduledAudits = [];

    /**
     * @var array<array{entity: object, data: array<string, mixed>, is_managed?: bool}>
     */
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

            // Check for auto-flush
            if (count($this->scheduledAudits) >= self::BATCH_FLUSH_THRESHOLD) {
                $this->logger?->warning('Auto-flushing audits due to batch size threshold', [
                    'count' => count($this->scheduledAudits),
                    'threshold' => self::BATCH_FLUSH_THRESHOLD,
                ]);

                $this->isFlushing = true;
                try {
                    $this->processScheduledAudits($em);
                } finally {
                    $this->isFlushing = false;
                }
            }

            // INSERT - Store for postFlush ID update
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

                // Always defer inserts to postFlush to ensure we have the ID
                $this->checkAuditLimit();
                $this->scheduledAudits[] = ['entity' => $entity, 'audit' => $audit, 'is_insert' => true];
            }

            // UPDATE
            foreach ($uow->getScheduledEntityUpdates() as $entity) {
                if (!$this->shouldProcessEntity($entity)) {
                    continue;
                }

                $changeSet = $uow->getEntityChangeSet($entity);
                [$old, $new] = $this->extractChanges($changeSet);

                $action = AuditLog::ACTION_UPDATE;

                // Detect Restore (Manual updates)
                if ($this->enableSoftDelete && \array_key_exists($this->softDeleteField, $changeSet)) {
                    $deletedAtChange = $changeSet[$this->softDeleteField];
                    if (null !== $deletedAtChange[0] && null === $deletedAtChange[1]) {
                        $action = AuditLog::ACTION_RESTORE;
                    }
                }

                $audit = $this->auditService->createAuditLog($entity, $action, $old, $new);

                if ($this->deferTransportUntilCommit) {
                    $this->checkAuditLimit();
                    $this->scheduledAudits[] = ['entity' => $entity, 'audit' => $audit, 'is_insert' => false];
                } else {
                    if (
                        !$this->safeSendToTransport($audit, [
                            'phase' => 'on_flush',
                            'em' => $em,
                            'uow' => $uow,
                        ])
                    ) {
                        $this->fallbackToDatabase($audit, $em, 'on_flush');
                    }
                }
            }

            // DELETE - Defer processing to postFlush to detect Soft Deletes (Gedmo interception)
            foreach ($uow->getScheduledEntityDeletions() as $entity) {
                if (!$this->shouldProcessEntity($entity)) {
                    continue;
                }
                // Capture original data now
                $this->pendingDeletions[] = [
                    'entity' => $entity,
                    'data' => $this->auditService->getEntityData($entity),
                    'is_managed' => $em->contains($entity),
                ];
            }
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

            // Process Deferred Deletions
            foreach ($this->pendingDeletions as $pending) {
                $entity = $pending['entity'];
                $oldData = $pending['data'];
                $isManaged = $pending['is_managed'] ?? $em->contains($entity);

                $action = $this->determineDeletionAction($em, $entity, $isManaged);

                if ($action) {
                    $newData = (AuditLog::ACTION_SOFT_DELETE === $action) ? $this->auditService->getEntityData($entity) : null;

                    $audit = $this->auditService->createAuditLog(
                        $entity,
                        $action,
                        $oldData,
                        $newData
                    );

                    // Explicitly persist the new audit log because the main transaction is closed
                    $em->persist($audit);
                    $hasNewAudits = true;

                    if (
                        !$this->safeSendToTransport($audit, [
                            'phase' => 'post_flush',
                            'em' => $em,
                            'entity' => $entity,
                        ])
                    ) {
                        $failedAudits[] = $audit;
                    }
                }
            }
            $this->pendingDeletions = [];

            // Process Scheduled Audits (ID updates for inserts)
            foreach ($this->scheduledAudits as $scheduled) {
                $audit = $scheduled['audit'];
                $entity = $scheduled['entity'];

                // Update ID for inserts if it was pending
                if ($scheduled['is_insert'] ?? false) {
                    $id = $this->auditService->getEntityId($entity);
                    if ('pending' !== $id) {
                        $audit->setEntityId($id);
                    }
                }

                if (
                    !$this->safeSendToTransport($audit, [
                        'phase' => 'post_flush',
                        'em' => $em,
                        'entity' => $entity,
                        'is_insert' => $scheduled['is_insert'] ?? false,
                    ])
                ) {
                    $failedAudits[] = $audit;
                }
            }
            $this->scheduledAudits = [];

            // Handle failed audits (Batch Persistence)
            if (!empty($failedAudits) && $this->fallbackToDatabase) {
                foreach ($failedAudits as $audit) {
                    if (!$em->contains($audit)) {
                        $em->persist($audit);
                        $hasNewAudits = true;
                    }
                }
            }

            if ($hasNewAudits) {
                $this->isFlushing = true;
                try {
                    $em->flush();

                    if (!empty($failedAudits)) {
                        $this->logger?->info('Successfully persisted {count} audits to database fallback', [
                            'count' => count($failedAudits),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger?->critical('Failed to flush fallback audits to database', [
                        'exception' => $e->getMessage(),
                        'count' => count($failedAudits),
                    ]);

                    if ($this->failOnTransportError) {
                        throw $e;
                    }
                } finally {
                    $this->isFlushing = false;
                }
            }
        } finally {
            --$this->recursionDepth;
        }
    }

    private function shouldProcessEntity(object $entity): bool
    {
        if ($entity instanceof AuditLog) {
            return false;
        }

        return $this->auditService->shouldAudit($entity);
    }

    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractChanges(array $changeSet): array
    {
        $old = [];
        $new = [];

        foreach ($changeSet as $field => $change) {
            if (!\is_array($change) || !\array_key_exists(0, $change) || !\array_key_exists(1, $change)) {
                continue;
            }

            [$oldValue, $newValue] = $change;

            if ($oldValue === $newValue) {
                continue;
            }
            $old[$field] = $oldValue;
            $new[$field] = $newValue;
        }

        return [$old, $new];
    }

    public function onClear(): void
    {
        $discardedAudits = count($this->scheduledAudits);
        $discardedDeletions = count($this->pendingDeletions);

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

    private function checkAuditLimit(): void
    {
        if (\count($this->scheduledAudits) >= self::MAX_SCHEDULED_AUDITS) {
            throw new \RuntimeException(\sprintf('Maximum audit queue size exceeded (%d). Consider batch processing or increase limit.', self::MAX_SCHEDULED_AUDITS));
        }
    }

    private function processScheduledAudits(EntityManagerInterface $em): void
    {
        $count = \count($this->scheduledAudits);

        for ($i = 0; $i < $count; ++$i) {
            if (!isset($this->scheduledAudits[$i])) {
                continue;
            }

            $scheduled = $this->scheduledAudits[$i];
            if (
                !$this->safeSendToTransport($scheduled['audit'], [
                    'phase' => 'batch_flush',
                    'em' => $em,
                    'entity' => $scheduled['entity'],
                ])
            ) {
                $this->fallbackToDatabase($scheduled['audit'], $em, 'on_flush');
            }

            // Free memory as we go
            unset($this->scheduledAudits[$i]);
        }
        $this->scheduledAudits = [];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return bool True if the audit was successfully sent to transport
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
                'context' => $context,
            ]);

            if ($this->failOnTransportError) {
                throw $e;
            }

            return false;
        }
    }

    private function fallbackToDatabase(AuditLog $audit, EntityManagerInterface $em, string $phase): void
    {
        if (!$this->fallbackToDatabase || !$em->isOpen()) {
            return;
        }

        try {
            if (!$em->contains($audit)) {
                $em->persist($audit);

                // Only compute changeset during onFlush phase
                if ('on_flush' === $phase) {
                    $uow = $em->getUnitOfWork();
                    $uow->computeChangeSet($em->getClassMetadata(AuditLog::class), $audit);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to persist audit log to database fallback', [
                'exception' => $e->getMessage(),
                'audit_action' => $audit->getAction(),
                'entity_class' => $audit->getEntityClass(),
            ]);
        }
    }

    private function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $wasManaged): ?string
    {
        // Try to detect soft delete by checking the soft delete field using ClassMetadata
        if ($this->enableSoftDelete) {
            $meta = $em->getClassMetadata($entity::class);
            if ($meta->hasField($this->softDeleteField)) {
                // Use reflection via metadata to get value safely
                $reflProp = $meta->getReflectionProperty($this->softDeleteField);
                if ($reflProp) {
                    $softDeleteValue = $reflProp->getValue($entity);

                    // If soft delete field is set, it's a soft delete
                    if (null !== $softDeleteValue) {
                        return AuditLog::ACTION_SOFT_DELETE;
                    }
                }
            }
        }

        // Hard delete detection
        if ($this->enableHardDelete) {
            // If not managed, definitely hard delete
            if (!$wasManaged) {
                return AuditLog::ACTION_DELETE;
            }

            // If managed and soft delete field is null (or doesn't exist),
            // it's a hard delete on a managed entity
            return AuditLog::ACTION_DELETE;
        }

        return null;
    }
}
