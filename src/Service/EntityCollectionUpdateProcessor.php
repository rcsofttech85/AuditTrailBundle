<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;

use function is_array;
use function spl_object_id;

final readonly class EntityCollectionUpdateProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditQueueManagerInterface $auditManager,
        private CollectionChangeResolver $collectionChangeResolver,
        private DeferredCollectionDetector $deferredCollectionDetector,
        private CollectionTransitionMerger $collectionTransitionMerger,
        private EntityAuditDispatchManager $dispatchManager,
    ) {
    }

    /**
     * @param iterable<object> $collectionUpdates
     */
    public function process(EntityManagerInterface $em, UnitOfWork $uow, iterable $collectionUpdates): void
    {
        foreach ($this->aggregateTransitionsByOwner($em, $uow, $collectionUpdates) as $transition) {
            $owner = $transition['entity'];
            $oldValues = $transition['old'];
            $newValues = $transition['new'];

            if (!$this->auditService->shouldAudit($owner, AuditAction::Update, $newValues)) {
                continue;
            }

            $deferredCollectionFields = $this->resolveDeferredCollectionFields($owner, $newValues, $em);
            if ($deferredCollectionFields === []) {
                $audit = $this->auditService->createAuditLog(
                    $owner,
                    AuditAction::Update,
                    $oldValues,
                    $newValues,
                    [],
                    $em,
                );

                $this->dispatchManager->dispatchOrSchedule($audit, $owner, $em, $uow, false);

                continue;
            }

            foreach ($deferredCollectionFields as $field) {
                unset($newValues[$field]);
            }

            $this->auditManager->schedulePendingAuditPlan(PendingAuditPlan::forDeferredCollections(
                $owner,
                AuditAction::Update,
                $oldValues,
                $newValues,
                $deferredCollectionFields,
            ));
        }
    }

    /**
     * @param iterable<object> $collectionUpdates
     *
     * @return array<int, array{
     *     entity: object,
     *     old: array<string, mixed>,
     *     new: array<string, mixed>
     * }>
     */
    private function aggregateTransitionsByOwner(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        iterable $collectionUpdates,
    ): array {
        $aggregatedTransitions = [];
        /** @var array<int, bool> $ownerCanProcess */
        $ownerCanProcess = [];

        foreach ($collectionUpdates as $collection) {
            if (!$this->collectionChangeResolver->isTrackableCollection($collection)) {
                continue;
            }

            $owner = $this->collectionChangeResolver->getCollectionOwner($collection);
            if ($owner === null) {
                continue;
            }

            $ownerId = spl_object_id($owner);
            $ownerCanProcess[$ownerId] ??= !$uow->isScheduledForInsert($owner) && !$uow->isScheduledForUpdate($owner);
            if (!$ownerCanProcess[$ownerId]) {
                continue;
            }

            $transition = $this->collectionChangeResolver->buildCollectionTransition($collection, $em);
            if ($transition === null) {
                continue;
            }

            $field = $transition['field'];
            $aggregatedTransitions[$ownerId] ??= [
                'entity' => $owner,
                'old' => [],
                'new' => [],
            ];

            $existingOldValue = $aggregatedTransitions[$ownerId]['old'][$field] ?? null;
            $existingNewValue = $aggregatedTransitions[$ownerId]['new'][$field] ?? null;
            if (!is_array($existingOldValue) || !is_array($existingNewValue)) {
                $aggregatedTransitions[$ownerId]['old'][$field] = $transition['old'];
                $aggregatedTransitions[$ownerId]['new'][$field] = $transition['new'];

                continue;
            }

            $this->collectionTransitionMerger->mergeSingleFieldTransition(
                $existingOldValue,
                $existingNewValue,
                $transition['old'],
                $transition['new'],
            );
            $aggregatedTransitions[$ownerId]['old'][$field] = $existingOldValue;
            $aggregatedTransitions[$ownerId]['new'][$field] = $existingNewValue;
        }

        return $aggregatedTransitions;
    }

    /**
     * @param array<string, mixed> $newValues
     *
     * @return list<string>
     */
    private function resolveDeferredCollectionFields(object $entity, array $newValues, EntityManagerInterface $em): array
    {
        $deferredCollectionFields = [];

        foreach ($newValues as $field => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (!$this->deferredCollectionDetector->shouldDeferCollectionFieldMaterialization($entity, $field, $em)) {
                continue;
            }

            $deferredCollectionFields[] = $field;
        }

        return $deferredCollectionFields;
    }
}
