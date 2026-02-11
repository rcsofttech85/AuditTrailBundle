<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function in_array;
use function is_string;

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
            $data = $this->auditService->getEntityData($entity);
            if (!$this->auditService->shouldAudit($entity, AuditLogInterface::ACTION_CREATE, $data)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLogInterface::ACTION_CREATE,
                null,
                $data
            );
            $this->dispatchOrSchedule($audit, $entity, $em, $uow, true);
        }
    }

    public function processUpdates(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            /* @var array<string, array{mixed, mixed}> $changeSet */
            [$old, $new] = $this->changeProcessor->extractChanges($entity, $changeSet);

            if ($old === [] && $new === []) {
                continue;
            }

            /** @var array<string, array{mixed, mixed}> $changeSet */
            $action = $this->changeProcessor->determineUpdateAction($changeSet);

            if (!$this->auditService->shouldAudit($entity, $action, $new)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new);
            $this->dispatchOrSchedule($audit, $entity, $em, $uow, false);
        }
    }

    /**
     * @param iterable<int, PersistentCollection<int|string, object>> $collectionUpdates
     */
    public function processCollectionUpdates(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        iterable $collectionUpdates,
    ): void {
        foreach ($collectionUpdates as $collection) {
            $owner = $collection->getOwner();
            if ($owner === null) {
                continue;
            }

            $insertDiff = $collection->getInsertDiff();
            $deleteDiff = $collection->getDeleteDiff();
            if ($insertDiff === [] && $deleteDiff === []) {
                continue;
            }

            $mapping = $collection->getMapping();
            $fieldName = $mapping['fieldName'];
            if (!is_string($fieldName)) {
                continue;
            }
            /** @var array<int, object> $snapshot */
            $snapshot = $collection->getSnapshot();
            $oldIds = $this->extractIdsFromCollection($snapshot, $em);
            /** @var array<int, object> $insertElements */
            $insertElements = array_values($insertDiff);
            /** @var array<int, object> $deleteElements */
            $deleteElements = array_values($deleteDiff);
            $newIds = $this->computeNewIds($oldIds, $insertElements, $deleteElements, $em);

            $oldValues = [$fieldName => $oldIds];
            $newValues = [$fieldName => $newIds];

            if (!$this->auditService->shouldAudit($owner, AuditLogInterface::ACTION_UPDATE, $newValues)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $owner,
                AuditLogInterface::ACTION_UPDATE,
                $oldValues,
                $newValues
            );
            $this->dispatchOrSchedule($audit, $owner, $em, $uow, false);
        }
    }

    public function processDeletions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $this->auditManager->addPendingDeletion(
                $entity,
                $this->auditService->getEntityData($entity),
                $em->contains($entity)
            );
        }
    }

    private function shouldProcessEntity(object $entity): bool
    {
        return !$entity instanceof AuditLog && $this->auditService->shouldAudit($entity);
    }

    private function dispatchOrSchedule(
        AuditLogInterface $audit,
        object $entity,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        bool $isInsert,
    ): void {
        if (
            !$this->deferTransportUntilCommit && $this->dispatcher->supports(
                'on_flush',
                ['em' => $em, 'uow' => $uow]
            )
        ) {
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
    private function extractIdsFromCollection(array $items, EntityManagerInterface $em): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = EntityIdResolver::resolveFromEntity($item, $em);
            if ($id !== EntityIdResolver::PENDING_ID) {
                $ids[] = $id;
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
    private function computeNewIds(
        array $oldIds,
        array $insertDiff,
        array $deleteDiff,
        EntityManagerInterface $em,
    ): array {
        $newIds = $oldIds;

        $insertedIds = $this->extractIdsFromCollection($insertDiff, $em);
        foreach ($insertedIds as $id) {
            if (!in_array($id, $newIds, true)) {
                $newIds[] = $id;
            }
        }

        $deletedIds = $this->extractIdsFromCollection($deleteDiff, $em);
        $newIds = array_filter($newIds, static fn ($id) => !in_array($id, $deletedIds, true));

        return array_values($newIds);
    }
}
