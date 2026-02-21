<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function in_array;
use function is_string;

final readonly class EntityProcessor implements EntityProcessorInterface
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ChangeProcessorInterface $changeProcessor,
        private AuditDispatcherInterface $dispatcher,
        private ScheduledAuditManagerInterface $auditManager,
        private EntityIdResolverInterface $idResolver,
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
     * @param iterable<object> $collectionUpdates
     */
    public function processCollectionUpdates(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        iterable $collectionUpdates,
    ): void {
        foreach ($collectionUpdates as $collection) {
            if (!method_exists($collection, 'getInsertDiff')) {
                continue;
            }
            /** @var PersistentCollection<int|string, object> $collection */
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
        AuditLog $audit,
        object $entity,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        bool $isInsert,
    ): void {
        // Smart flush detection
        $canDispatchNow = !$this->deferTransportUntilCommit
            || ($isInsert && $audit->entityId !== AuditLogInterface::PENDING_ID);

        if ($canDispatchNow && $this->dispatcher->dispatch($audit, $em, 'on_flush', $uow)) {
            return;
        }

        $this->auditManager->schedule($entity, $audit, $isInsert);
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
            $id = $this->idResolver->resolveFromEntity($item, $em);
            if ($id !== AuditLogInterface::PENDING_ID) {
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

        return array_filter($newIds, static fn ($id) => !in_array($id, $deletedIds, true));
    }
}
