<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

use function array_key_exists;
use function is_array;
use function spl_object_id;

final readonly class EntityProcessor implements EntityProcessorInterface
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ChangeProcessorInterface $changeProcessor,
        private AuditDispatcherInterface $dispatcher,
        private ScheduledAuditManagerInterface $auditManager,
        private AssociationImpactAnalyzer $associationImpactAnalyzer,
        private CollectionChangeResolver $collectionChangeResolver,
        private CollectionTransitionMerger $collectionTransitionMerger,
        private bool $deferTransportUntilCommit = true,
        private bool $failOnTransportError = false,
    ) {
    }

    #[Override]
    public function processInsertions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $data = $this->auditService->getEntityData($entity, [], $em);
            if (!$this->auditService->shouldAudit($entity, AuditLogInterface::ACTION_CREATE, $data)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLogInterface::ACTION_CREATE,
                null,
                $data,
                [],
                $em,
            );
            $this->dispatchOrSchedule($audit, $entity, $em, $uow, true);
        }
    }

    #[Override]
    public function processUpdates(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $deletedAssociationImpacts ??= $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($em, $uow);
        $deletedAssociationImpactsByOwner = $this->indexDeletedAssociationImpactsByOwner($deletedAssociationImpacts);
        $collectionChangesByOwner = $this->collectionChangeResolver->extractCollectionChangesIndexedByOwner($em, $uow);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            /* @var array<string, array{mixed, mixed}> $changeSet */
            [$old, $new] = $this->changeProcessor->extractChanges($entity, $changeSet);
            [$collectionOld, $collectionNew] = $this->collectionChangeResolver->extractIndexedCollectionChangesForOwner(
                $entity,
                $collectionChangesByOwner,
            );
            [$deletedAssocOld, $deletedAssocNew] = $this->extractDeletedAssociationChangesForOwner(
                $entity,
                $deletedAssociationImpactsByOwner,
            );
            $this->mergeFieldTransitions($old, $new, $collectionOld, $collectionNew);
            $this->mergeFieldTransitions($old, $new, $deletedAssocOld, $deletedAssocNew);

            if ($old === [] && $new === []) {
                continue;
            }

            /** @var array<string, array{mixed, mixed}> $changeSet */
            $action = $this->changeProcessor->determineUpdateAction($changeSet);

            if (!$this->auditService->shouldAudit($entity, $action, $new)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new, [], $em);
            $this->dispatchOrSchedule($audit, $entity, $em, $uow, false);
        }
    }

    /**
     * @param iterable<object> $collectionUpdates
     */
    #[Override]
    public function processCollectionUpdates(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        iterable $collectionUpdates,
    ): void {
        foreach ($collectionUpdates as $collection) {
            if (!$this->collectionChangeResolver->isTrackableCollection($collection)) {
                continue;
            }

            $owner = $this->collectionChangeResolver->getCollectionOwner($collection);
            if ($owner === null) {
                continue;
            }

            if ($this->isScheduledForInsertion($owner, $uow) || $this->isScheduledForUpdate($owner, $uow)) {
                continue;
            }

            $transition = $this->collectionChangeResolver->buildCollectionTransition($collection, $em);
            if ($transition === null) {
                continue;
            }

            $oldValues = [$transition['field'] => $transition['old']];
            $newValues = [$transition['field'] => $transition['new']];

            if (!$this->auditService->shouldAudit($owner, AuditLogInterface::ACTION_UPDATE, $newValues)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $owner,
                AuditLogInterface::ACTION_UPDATE,
                $oldValues,
                $newValues,
                [],
                $em,
            );
            $this->dispatchOrSchedule($audit, $owner, $em, $uow, false);
        }
    }

    #[Override]
    public function processDeletions(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $this->processRelatedEntityCollectionImpacts(
            $deletedAssociationImpacts ?? $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($em, $uow),
            $em,
            $uow,
        );

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            if ($this->isAlreadyTrackedAsSoftDelete($entity, $uow)) {
                continue;
            }

            $this->auditManager->addPendingDeletion(
                $entity,
                $this->auditService->getEntityData($entity, [], $em),
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
        $hasResolvedEntityId = $audit->entityId !== AuditLogInterface::PENDING_ID;
        $canDispatchNow = (!$isInsert && !$this->deferTransportUntilCommit)
            || ($isInsert && !$hasResolvedEntityId && !$this->deferTransportUntilCommit && $this->failOnTransportError)
            || ($isInsert && $hasResolvedEntityId);

        if ($canDispatchNow && $this->dispatcher->dispatch($audit, $em, AuditPhase::OnFlush, $uow, $entity)) {
            return;
        }

        $this->auditManager->schedule($entity, $audit, $isInsert);
    }

    private function isAlreadyTrackedAsSoftDelete(object $entity, UnitOfWork $uow): bool
    {
        $changeSet = $uow->getEntityChangeSet($entity);

        return $this->isSoftDeleteChangeSet($changeSet);
    }

    private function isScheduledForInsertion(object $entity, UnitOfWork $uow): bool
    {
        return $uow->isScheduledForInsert($entity);
    }

    private function isScheduledForUpdate(object $entity, UnitOfWork $uow): bool
    {
        return $uow->isScheduledForUpdate($entity);
    }

    /**
     * @param list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $impacts
     *
     * @return array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>>
     */
    private function indexDeletedAssociationImpactsByOwner(array $impacts): array
    {
        $indexed = [];

        foreach ($impacts as $impact) {
            $indexed[spl_object_id($impact['entity'])][$impact['field']] = [
                'old' => $impact['old'],
                'new' => $impact['new'],
            ];
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>> $indexedImpacts
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractDeletedAssociationChangesForOwner(object $owner, array $indexedImpacts): array
    {
        $ownerImpacts = $indexedImpacts[spl_object_id($owner)] ?? null;
        if ($ownerImpacts === null) {
            return [[], []];
        }

        $oldValues = [];
        $newValues = [];

        foreach ($ownerImpacts as $field => $impact) {
            $oldValues[$field] = $impact['old'];
            $newValues[$field] = $impact['new'];
        }

        return [$oldValues, $newValues];
    }

    /**
     * @param list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $impacts
     */
    private function processRelatedEntityCollectionImpacts(
        array $impacts,
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): void {
        foreach ($impacts as $impact) {
            $relatedEntity = $impact['entity'];

            if ($this->isScheduledForInsertion($relatedEntity, $uow) || $this->isScheduledForUpdate($relatedEntity, $uow)) {
                continue;
            }

            $newValues = [$impact['field'] => $impact['new']];
            if (!$this->auditService->shouldAudit($relatedEntity, AuditLogInterface::ACTION_UPDATE, $newValues)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $relatedEntity,
                AuditLogInterface::ACTION_UPDATE,
                [$impact['field'] => $impact['old']],
                $newValues,
                [],
                $em,
            );
            $this->dispatchOrSchedule($audit, $relatedEntity, $em, $uow, false);
        }
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $incomingOldValues
     * @param array<string, mixed> $incomingNewValues
     */
    private function mergeFieldTransitions(
        array &$oldValues,
        array &$newValues,
        array $incomingOldValues,
        array $incomingNewValues,
    ): void {
        foreach ($incomingNewValues as $field => $incomingNewValue) {
            $incomingOldValue = $incomingOldValues[$field] ?? [];

            if (!$this->canMergeFieldTransition($oldValues, $newValues, $field, $incomingOldValue, $incomingNewValue)) {
                $oldValues[$field] = $incomingOldValue;
                $newValues[$field] = $incomingNewValue;
                continue;
            }

            $this->collectionTransitionMerger->mergeSingleFieldTransition(
                $oldValues[$field],
                $newValues[$field],
                $incomingOldValue,
                $incomingNewValue,
            );
        }
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function canMergeFieldTransition(
        array $oldValues,
        array $newValues,
        string $field,
        mixed $incomingOldValue,
        mixed $incomingNewValue,
    ): bool {
        return isset($oldValues[$field], $newValues[$field])
            && is_array($oldValues[$field])
            && is_array($newValues[$field])
            && is_array($incomingOldValue)
            && is_array($incomingNewValue);
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    private function isSoftDeleteChangeSet(array $changeSet): bool
    {
        if ($changeSet === []) {
            return false;
        }

        foreach ($changeSet as $change) {
            if (!is_array($change) || !array_key_exists(0, $change) || !array_key_exists(1, $change)) {
                return false;
            }
        }

        /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
        return $this->changeProcessor->determineUpdateAction($changeSet) === AuditLogInterface::ACTION_SOFT_DELETE;
    }
}
