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
            $resolvedTransition = $this->resolveOwnerTransition($collection, $em);
            if ($resolvedTransition === null) {
                continue;
            }

            $owner = $resolvedTransition['owner'];
            $ownerId = spl_object_id($owner);
            if (!$this->canProcessOwner($owner, $ownerId, $uow, $ownerCanProcess)) {
                continue;
            }

            $this->mergeOwnerTransition(
                $aggregatedTransitions,
                $ownerId,
                $owner,
                $resolvedTransition['transition'],
            );
        }

        return $aggregatedTransitions;
    }

    /**
     * @return array{
     *     owner: object,
     *     transition: array{field: string, old: array<int, int|string>, new: array<int, int|string>}
     * }|null
     */
    private function resolveOwnerTransition(object $collection, EntityManagerInterface $em): ?array
    {
        if (!$this->collectionChangeResolver->isTrackableCollection($collection)) {
            return null;
        }

        $owner = $this->collectionChangeResolver->getCollectionOwner($collection);
        if ($owner === null) {
            return null;
        }

        $transition = $this->collectionChangeResolver->buildCollectionTransition($collection, $em);
        if ($transition === null) {
            return null;
        }

        return [
            'owner' => $owner,
            'transition' => $transition,
        ];
    }

    /**
     * @param array<int, bool> $ownerCanProcess
     */
    private function canProcessOwner(
        object $owner,
        int $ownerId,
        UnitOfWork $uow,
        array &$ownerCanProcess,
    ): bool {
        $ownerCanProcess[$ownerId] ??= !$uow->isScheduledForInsert($owner) && !$uow->isScheduledForUpdate($owner);

        return $ownerCanProcess[$ownerId];
    }

    /**
     * @param array<int, array{
     *     entity: object,
     *     old: array<string, mixed>,
     *     new: array<string, mixed>
     * }> $aggregatedTransitions
     * @param array{field: string, old: array<int, int|string>, new: array<int, int|string>} $transition
     */
    private function mergeOwnerTransition(
        array &$aggregatedTransitions,
        int $ownerId,
        object $owner,
        array $transition,
    ): void {
        $field = $transition['field'];
        $this->initializeOwnerTransitionBucket($aggregatedTransitions, $ownerId, $owner);

        $existingOldValue = $aggregatedTransitions[$ownerId]['old'][$field] ?? null;
        $existingNewValue = $aggregatedTransitions[$ownerId]['new'][$field] ?? null;
        if ($this->shouldStoreUnmergedTransition($existingOldValue, $existingNewValue)) {
            $this->storeOwnerTransitionField($aggregatedTransitions[$ownerId], $field, $transition);

            return;
        }

        $this->mergeExistingOwnerTransitionField(
            $aggregatedTransitions[$ownerId],
            $field,
            $existingOldValue,
            $existingNewValue,
            $transition,
        );
    }

    /**
     * @param array<int, array{
     *     entity: object,
     *     old: array<string, mixed>,
     *     new: array<string, mixed>
     * }> $aggregatedTransitions
     */
    private function initializeOwnerTransitionBucket(array &$aggregatedTransitions, int $ownerId, object $owner): void
    {
        $aggregatedTransitions[$ownerId] ??= [
            'entity' => $owner,
            'old' => [],
            'new' => [],
        ];
    }

    private function shouldStoreUnmergedTransition(mixed $existingOldValue, mixed $existingNewValue): bool
    {
        return !is_array($existingOldValue) || !is_array($existingNewValue);
    }

    /**
     * @param array{
     *     entity: object,
     *     old: array<string, mixed>,
     *     new: array<string, mixed>
     * } $ownerTransitionBucket
     * @param array{field: string, old: array<int, int|string>, new: array<int, int|string>} $transition
     */
    private function storeOwnerTransitionField(array &$ownerTransitionBucket, string $field, array $transition): void
    {
        $ownerTransitionBucket['old'][$field] = $transition['old'];
        $ownerTransitionBucket['new'][$field] = $transition['new'];
    }

    /**
     * @param array{
     *     entity: object,
     *     old: array<string, mixed>,
     *     new: array<string, mixed>
     * } $ownerTransitionBucket
     * @param array<int, int|string>                                                         $existingOldValue
     * @param array<int, int|string>                                                         $existingNewValue
     * @param array{field: string, old: array<int, int|string>, new: array<int, int|string>} $transition
     */
    private function mergeExistingOwnerTransitionField(
        array &$ownerTransitionBucket,
        string $field,
        array $existingOldValue,
        array $existingNewValue,
        array $transition,
    ): void {
        $this->collectionTransitionMerger->mergeSingleFieldTransition(
            $existingOldValue,
            $existingNewValue,
            $transition['old'],
            $transition['new'],
        );
        $ownerTransitionBucket['old'][$field] = $existingOldValue;
        $ownerTransitionBucket['new'][$field] = $existingNewValue;
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
