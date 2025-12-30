<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

readonly class EntityProcessor
{
    public function __construct(
        private AuditService $auditService,
        private ChangeProcessor $changeProcessor,
        private AuditDispatcher $dispatcher,
        private ScheduledAuditManager $auditManager,
        private bool $deferTransportUntilCommit = true,
    ) {
    }

    public function processInsertions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog($entity, AuditLog::ACTION_CREATE, null, $this->auditService->getEntityData($entity));
            $this->dispatchOrSchedule($audit, $entity, $em, $uow, true);
        }
    }

    public function processUpdates(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            /* @var array<string, array{mixed, mixed}> $changeSet */
            [$old, $new] = $this->changeProcessor->extractChanges($entity, $changeSet);

            if ([] === $old && [] === $new) {
                continue;
            }

            /** @var array<string, array{mixed, mixed}> $changeSet */
            $action = $this->changeProcessor->determineUpdateAction($changeSet);
            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new);
            $this->dispatchOrSchedule($audit, $entity, $em, $uow, false);
        }
    }

    /**
     * @param iterable<int, PersistentCollection<int|string, object>> $collectionUpdates
     */
    public function processCollectionUpdates(EntityManagerInterface $em, UnitOfWork $uow, iterable $collectionUpdates): void
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
            $fieldName = (string) $mapping['fieldName'];
            $snapshot = $collection->getSnapshot();
            /** @var array<int, object> $snapshot */
            $oldIds = $this->extractIdsFromCollection($snapshot);
            $newIds = $this->computeNewIds($oldIds, array_values($insertDiff), array_values($deleteDiff));

            $audit = $this->auditService->createAuditLog($owner, AuditLog::ACTION_UPDATE, [$fieldName => $oldIds], [$fieldName => $newIds]);
            $this->dispatchOrSchedule($audit, $owner, $em, $uow, false);
        }
    }

    public function processDeletions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $this->auditManager->addPendingDeletion($entity, $this->auditService->getEntityData($entity), $em->contains($entity));
        }
    }

    private function shouldProcessEntity(object $entity): bool
    {
        return !$entity instanceof AuditLog && $this->auditService->shouldAudit($entity);
    }

    private function dispatchOrSchedule(AuditLogInterface $audit, object $entity, EntityManagerInterface $em, UnitOfWork $uow, bool $isInsert): void
    {
        if (!$this->deferTransportUntilCommit && $this->dispatcher->supports('on_flush', ['em' => $em, 'uow' => $uow])) {
            $this->dispatcher->dispatch($audit, $em, 'on_flush', $uow);
        } else {
            $this->auditManager->schedule($entity, $audit, $isInsert);
        }
    }

    /**
     * @param array<int, object> $items
     *
     * @return array<int, int|string>
     */
    private function extractIdsFromCollection(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (\method_exists($item, 'getId')) {
                $id = $item->getId();
                if (\is_int($id) || \is_string($id)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<int, int|string> $oldIds
     * @param array<int, object>     $insertDiff
     * @param array<int, object>     $deleteDiff
     *
     * @return array<int, int|string>
     */
    private function computeNewIds(array $oldIds, array $insertDiff, array $deleteDiff): array
    {
        $newIds = $oldIds;

        $insertedIds = $this->extractIdsFromCollection($insertDiff);
        foreach ($insertedIds as $id) {
            if (!\in_array($id, $newIds, true)) {
                $newIds[] = $id;
            }
        }

        $deletedIds = $this->extractIdsFromCollection($deleteDiff);
        $newIds = array_filter($newIds, fn($id) => !in_array($id, $deletedIds, true));

        return \array_values($newIds);
    }
}
