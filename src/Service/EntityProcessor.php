<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
use Stringable;
use Throwable;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_iterable;
use function is_object;
use function is_string;
use function spl_object_id;

final readonly class EntityProcessor implements EntityProcessorInterface
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ChangeProcessorInterface $changeProcessor,
        private AuditDispatcherInterface $dispatcher,
        private ScheduledAuditManagerInterface $auditManager,
        private EntityIdResolverInterface $idResolver,
        private bool $deferTransportUntilCommit = true,
        private bool $failOnTransportError = false,
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
        $deletedAssociationImpacts = $this->buildAggregatedDeletedAssociationImpacts($em, $uow);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            /* @var array<string, array{mixed, mixed}> $changeSet */
            [$old, $new] = $this->changeProcessor->extractChanges($entity, $changeSet);
            [$collectionOld, $collectionNew] = $this->extractCollectionChangesForOwner($entity, $em, $uow);
            [$deletedAssocOld, $deletedAssocNew] = $this->extractDeletedEntityAssociationChangesForOwner($entity, $deletedAssociationImpacts);
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

            if ($this->isScheduledForInsertion($owner, $uow) || $this->isScheduledForUpdate($owner, $uow)) {
                continue;
            }

            $transition = $this->buildCollectionTransition($collection, $em);
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
                $newValues
            );
            $this->dispatchOrSchedule($audit, $owner, $em, $uow, false);
        }
    }

    public function processDeletions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        $this->processRelatedEntityCollectionImpacts(
            $this->buildAggregatedDeletedAssociationImpacts($em, $uow),
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
        $hasResolvedEntityId = $audit->entityId !== AuditLogInterface::PENDING_ID;
        $canDispatchNow = (!$isInsert && !$this->deferTransportUntilCommit)
            || ($isInsert && !$hasResolvedEntityId && !$this->deferTransportUntilCommit && $this->failOnTransportError)
            || ($isInsert && $hasResolvedEntityId);

        if ($canDispatchNow && $this->dispatcher->dispatch($audit, $em, 'on_flush', $uow, $entity)) {
            return;
        }

        $this->auditManager->schedule($entity, $audit, $isInsert);
    }

    private function isAlreadyTrackedAsSoftDelete(object $entity, UnitOfWork $uow): bool
    {
        $changeSet = $uow->getEntityChangeSet($entity);
        if ($this->isSoftDeleteChangeSet($changeSet)) {
            return true;
        }

        foreach ($uow->getScheduledEntityUpdates() as $updatedEntity) {
            if ($updatedEntity !== $entity) {
                continue;
            }

            $updateChangeSet = $uow->getEntityChangeSet($updatedEntity);

            return $this->isSoftDeleteChangeSet($updateChangeSet);
        }

        return false;
    }

    private function isScheduledForInsertion(object $entity, UnitOfWork $uow): bool
    {
        foreach ($uow->getScheduledEntityInsertions() as $scheduledEntity) {
            if ($scheduledEntity === $entity) {
                return true;
            }
        }

        return false;
    }

    private function isScheduledForUpdate(object $entity, UnitOfWork $uow): bool
    {
        foreach ($uow->getScheduledEntityUpdates() as $scheduledEntity) {
            if ($scheduledEntity === $entity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $impacts
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractDeletedEntityAssociationChangesForOwner(
        object $owner,
        array $impacts,
    ): array {
        $oldValues = [];
        $newValues = [];

        foreach ($impacts as $impact) {
            if ($impact['entity'] !== $owner) {
                continue;
            }

            $oldValues[$impact['field']] = $impact['old'];
            $newValues[$impact['field']] = $impact['new'];
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
                $newValues
            );
            $this->dispatchOrSchedule($audit, $relatedEntity, $em, $uow, false);
        }
    }

    /**
     * @return list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}>
     */
    private function buildAggregatedDeletedAssociationImpacts(EntityManagerInterface $em, UnitOfWork $uow): array
    {
        /** @var array<string, array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $aggregated */
        $aggregated = [];

        foreach ($uow->getScheduledEntityDeletions() as $deletedEntity) {
            foreach ($this->buildRelatedEntityCollectionImpacts($deletedEntity, $em) as $impact) {
                $key = spl_object_id($impact['entity']).':'.$impact['field'];
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = $impact;
                    continue;
                }

                $this->mergeSingleFieldTransition(
                    $aggregated[$key]['old'],
                    $aggregated[$key]['new'],
                    $impact['old'],
                    $impact['new'],
                );
            }
        }

        return array_values($aggregated);
    }

    /**
     * @return list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}>
     */
    private function buildRelatedEntityCollectionImpacts(object $deletedEntity, EntityManagerInterface $em): array
    {
        $deletedId = $this->idResolver->resolveFromEntity($deletedEntity, $em);
        if ($deletedId === AuditLogInterface::PENDING_ID) {
            return [];
        }

        $metadata = $em->getClassMetadata($deletedEntity::class);
        $impacts = [];

        foreach ($metadata->getAssociationNames() as $associationName) {
            $impacts = [...$impacts, ...$this->buildAssociationCollectionImpacts(
                $deletedEntity,
                $metadata,
                $associationName,
                $deletedId,
                $em,
            )];
        }

        return $impacts;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}>
     */
    private function buildAssociationCollectionImpacts(
        object $deletedEntity,
        ClassMetadata $metadata,
        string $associationName,
        int|string $deletedId,
        EntityManagerInterface $em,
    ): array {
        $counterpartField = $this->resolveCounterpartFieldFromMapping($metadata->getAssociationMapping($associationName));
        if ($counterpartField === null) {
            return [];
        }

        $relatedEntities = $metadata->getFieldValue($deletedEntity, $associationName);
        if (!is_iterable($relatedEntities)) {
            return [];
        }

        $impacts = [];
        foreach ($relatedEntities as $relatedEntity) {
            $impact = $this->buildSingleRelatedEntityImpact($relatedEntity, $counterpartField, $deletedId, $em);
            if ($impact !== null) {
                $impacts[] = $impact;
            }
        }

        return $impacts;
    }

    private function resolveCounterpartFieldFromMapping(mixed $mapping): ?string
    {
        $counterpartField = $mapping['mappedBy'] ?? $mapping['inversedBy'] ?? null;

        return is_string($counterpartField) ? $counterpartField : null;
    }

    /**
     * @return array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}|null
     */
    private function buildSingleRelatedEntityImpact(
        object $relatedEntity,
        string $counterpartField,
        int|string $deletedId,
        EntityManagerInterface $em,
    ): ?array {
        $relatedMetadata = $em->getClassMetadata($relatedEntity::class);
        $relatedCollection = $relatedMetadata->getFieldValue($relatedEntity, $counterpartField);
        if (!is_iterable($relatedCollection)) {
            return null;
        }

        $oldIds = $this->extractIdsFromIterable($relatedCollection, $em);
        $newIds = array_values(array_filter($oldIds, static fn ($id) => $id !== $deletedId));
        if ($oldIds === $newIds) {
            return null;
        }

        return [
            'entity' => $relatedEntity,
            'field' => $counterpartField,
            'old' => $oldIds,
            'new' => $newIds,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractCollectionChangesForOwner(
        object $owner,
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): array {
        $oldValues = [];
        $newValues = [];

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $this->mergeCollectionChange($collection, $owner, $em, $oldValues, $newValues);
        }

        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $this->mergeCollectionChange($collection, $owner, $em, $oldValues, $newValues);
        }

        $this->mergeOriginalCollectionChangesForOwner($owner, $em, $uow, $oldValues, $newValues);

        return [$oldValues, $newValues];
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

            $this->mergeSingleFieldTransition(
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
     * @param array<int, int|string> $existingOld
     * @param array<int, int|string> $existingNew
     * @param array<int, int|string> $incomingOld
     * @param array<int, int|string> $incomingNew
     */
    private function mergeSingleFieldTransition(
        array &$existingOld,
        array &$existingNew,
        array $incomingOld,
        array $incomingNew,
    ): void {
        $combinedOld = $this->mergeUniqueIds($existingOld, $incomingOld);
        $deletedIds = $this->mergeUniqueIds(
            $this->diffIds($existingOld, $existingNew),
            $this->diffIds($incomingOld, $incomingNew),
        );
        $addedIds = $this->mergeUniqueIds(
            $this->diffIds($existingNew, $existingOld),
            $this->diffIds($incomingNew, $incomingOld),
        );

        $baseNew = $this->diffIds($combinedOld, $deletedIds);
        $existingOld = $combinedOld;
        $existingNew = $this->mergeUniqueIds($baseNew, $addedIds);
    }

    /**
     * @param array<int, int|string> $left
     * @param array<int, int|string> $right
     *
     * @return array<int, int|string>
     */
    private function mergeUniqueIds(array $left, array $right): array
    {
        $merged = $left;

        foreach ($right as $id) {
            if (!in_array($id, $merged, true)) {
                $merged[] = $id;
            }
        }

        return $merged;
    }

    /**
     * @param array<int, int|string> $source
     * @param array<int, int|string> $toRemove
     *
     * @return array<int, int|string>
     */
    private function diffIds(array $source, array $toRemove): array
    {
        return array_values(array_filter($source, static fn ($id) => !in_array($id, $toRemove, true)));
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function mergeCollectionChange(
        object $collection,
        object $owner,
        EntityManagerInterface $em,
        array &$oldValues,
        array &$newValues,
    ): void {
        if (!method_exists($collection, 'getInsertDiff') || !method_exists($collection, 'getOwner')) {
            return;
        }

        $collectionOwner = $collection->getOwner();
        if ($collectionOwner !== $owner) {
            return;
        }

        $transition = $this->buildCollectionTransition($collection, $em);
        if ($transition === null) {
            return;
        }

        $oldValues[$transition['field']] = $transition['old'];
        $newValues[$transition['field']] = $transition['new'];
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function mergeOriginalCollectionChangesForOwner(
        object $owner,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        array &$oldValues,
        array &$newValues,
    ): void {
        $metadata = $em->getClassMetadata($owner::class);
        $originalData = $uow->getOriginalEntityData($owner);

        foreach ($metadata->getAssociationNames() as $associationName) {
            if ($this->shouldSkipOriginalCollectionMerge($metadata, $associationName, $oldValues)) {
                continue;
            }

            $currentValue = $metadata->getFieldValue($owner, $associationName);
            if (!is_iterable($currentValue)) {
                continue;
            }

            $originalValue = $this->resolveOriginalCollectionValue($currentValue, $originalData, $associationName);
            if (!is_iterable($originalValue)) {
                continue;
            }

            $transition = $this->resolveOriginalCollectionTransition(
                $owner,
                $associationName,
                $currentValue,
                $originalValue,
                $em,
            );
            if ($transition === null) {
                continue;
            }

            $oldValues[$associationName] = $transition['old'];
            $newValues[$associationName] = $transition['new'];
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $oldValues
     */
    private function shouldSkipOriginalCollectionMerge(
        ClassMetadata $metadata,
        string $associationName,
        array $oldValues,
    ): bool {
        return isset($oldValues[$associationName]) || !$metadata->isCollectionValuedAssociation($associationName);
    }

    /**
     * @param array<string, mixed> $originalData
     */
    private function resolveOriginalCollectionValue(
        mixed $currentValue,
        array $originalData,
        string $associationName,
    ): mixed {
        if (is_object($currentValue) && method_exists($currentValue, 'getSnapshot')) {
            return $currentValue->getSnapshot();
        }

        return $originalData[$associationName] ?? null;
    }

    /**
     * @param iterable<mixed> $currentValue
     * @param iterable<mixed> $originalValue
     *
     * @return array{old: array<int, int|string>, new: array<int, int|string>}|null
     */
    private function resolveOriginalCollectionTransition(
        object $owner,
        string $associationName,
        iterable $currentValue,
        iterable $originalValue,
        EntityManagerInterface $em,
    ): ?array {
        $originalIds = $this->extractIdsFromIterable($originalValue, $em);
        $currentIds = $this->extractIdsFromIterable($currentValue, $em);
        if ($originalIds === $currentIds && $currentIds === []) {
            $originalIds = $this->loadOriginalCollectionIdsFromDatabase($owner, $associationName, $em);
        }

        if ($originalIds === $currentIds) {
            return null;
        }

        return ['old' => $originalIds, 'new' => $currentIds];
    }

    /**
     * @return array{field: string, old: array<int, int|string>, new: array<int, int|string>}|null
     */
    private function buildCollectionTransition(object $collection, EntityManagerInterface $em): ?array
    {
        /** @var PersistentCollection<int|string, object> $collection */
        $mapping = $collection->getMapping();
        $fieldName = $mapping['fieldName'] ?? null;
        if (!is_string($fieldName)) {
            return null;
        }

        /** @var array<int, object> $snapshot */
        $snapshot = $collection->getSnapshot();
        $oldIds = $this->extractIdsFromCollection($snapshot, $em);
        /** @var array<int, object> $insertElements */
        $insertElements = array_values($collection->getInsertDiff());
        /** @var array<int, object> $deleteElements */
        $deleteElements = array_values($collection->getDeleteDiff());

        if ($insertElements === [] && $deleteElements === []) {
            if ($oldIds === []) {
                return null;
            }

            return [
                'field' => $fieldName,
                'old' => $oldIds,
                'new' => [],
            ];
        }

        return [
            'field' => $fieldName,
            'old' => $oldIds,
            'new' => $this->computeNewIds($oldIds, $insertElements, $deleteElements, $em),
        ];
    }

    /**
     * @return array<int, int|string>
     */
    private function loadOriginalCollectionIdsFromDatabase(
        object $owner,
        string $associationName,
        EntityManagerInterface $em,
    ): array {
        $queryContext = $this->buildCollectionQueryContext($owner, $associationName, $em);
        if ($queryContext === null) {
            return [];
        }

        $rows = $this->fetchOriginalCollectionRows($queryContext, $em);

        return $this->normalizeLoadedIdentifiers($rows, $queryContext['inverseIdType'], $em);
    }

    /**
     * @return array{
     *     ownerId: int|string,
     *     joinTable: string,
     *     joinColumn: string,
     *     inverseJoinColumn: string,
     *     ownerIdType: ?string,
     *     inverseIdType: ?string
     * }|null
     */
    private function buildCollectionQueryContext(
        object $owner,
        string $associationName,
        EntityManagerInterface $em,
    ): ?array {
        $ownerId = $this->idResolver->resolveFromEntity($owner, $em);
        if ($ownerId === AuditLogInterface::PENDING_ID) {
            return null;
        }

        $metadata = $em->getClassMetadata($owner::class);
        $mapping = $metadata->getAssociationMapping($associationName);
        if (!$this->isValidOwningCollectionMapping($mapping)) {
            return null;
        }

        $joinTable = $this->readMappingValue($mapping, 'joinTable');
        $joinColumn = $this->extractJoinColumnDefinition($joinTable, 'joinColumns');
        $inverseJoinColumn = $this->extractJoinColumnDefinition($joinTable, 'inverseJoinColumns');
        $joinTableName = $this->extractJoinTableName($joinTable);
        if ($joinColumn === null || $inverseJoinColumn === null || $joinTableName === null) {
            return null;
        }

        /** @var class-string<object> $targetEntity */
        $targetEntity = $mapping['targetEntity'];
        $targetMetadata = $em->getClassMetadata($targetEntity);
        $joinColumnReference = $joinColumn['referencedColumnName'];
        $inverseJoinColumnReference = $inverseJoinColumn['referencedColumnName'];

        return [
            'ownerId' => $ownerId,
            'joinTable' => $joinTableName,
            'joinColumn' => $joinColumn['name'],
            'inverseJoinColumn' => $inverseJoinColumn['name'],
            'ownerIdType' => $this->resolveColumnType($metadata, $joinColumnReference),
            'inverseIdType' => $this->resolveColumnType($targetMetadata, $inverseJoinColumnReference),
        ];
    }

    private function isValidOwningCollectionMapping(mixed $mapping): bool
    {
        if (($mapping['isOwningSide'] ?? false) !== true) {
            return false;
        }

        return $this->hasValidJoinTableStructure($mapping['joinTable'] ?? null);
    }

    private function hasValidJoinTableStructure(mixed $joinTable): bool
    {
        return $this->containsRequiredJoinTableName($joinTable)
            && $this->hasValidJoinColumnDefinition($this->readFirstJoinColumnDefinition($joinTable, 'joinColumns'))
            && $this->hasValidJoinColumnDefinition($this->readFirstJoinColumnDefinition($joinTable, 'inverseJoinColumns'));
    }

    private function containsRequiredJoinTableName(mixed $joinTable): bool
    {
        return $this->isNonEmptyString($this->extractJoinTableName($joinTable));
    }

    private function hasValidJoinColumnDefinition(mixed $definition): bool
    {
        return $this->extractJoinColumnDefinitionValues($definition) !== null;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function extractJoinTableName(mixed $joinTable): ?string
    {
        $joinTableName = $this->readMappingValue($joinTable, 'name');

        return $this->isNonEmptyString($joinTableName) ? $joinTableName : null;
    }

    private function readFirstJoinColumnDefinition(mixed $joinTable, string $columnGroup): mixed
    {
        $definitions = $this->readMappingValue($joinTable, $columnGroup);
        if (!is_array($definitions)) {
            return null;
        }

        return $definitions[0] ?? null;
    }

    /**
     * @return array{name: string, referencedColumnName: string}|null
     */
    private function extractJoinColumnDefinition(mixed $joinTable, string $columnGroup): ?array
    {
        return $this->extractJoinColumnDefinitionValues($this->readFirstJoinColumnDefinition($joinTable, $columnGroup));
    }

    /**
     * @return array{name: string, referencedColumnName: string}|null
     */
    private function extractJoinColumnDefinitionValues(mixed $definition): ?array
    {
        $name = $this->readMappingValue($definition, 'name');
        $referencedColumnName = $this->readMappingValue($definition, 'referencedColumnName');
        if (!$this->isNonEmptyString($name) || !$this->isNonEmptyString($referencedColumnName)) {
            return null;
        }

        return [
            'name' => $name,
            'referencedColumnName' => $referencedColumnName,
        ];
    }

    private function readMappingValue(mixed $mapping, string $key): mixed
    {
        if (is_array($mapping)) {
            return $mapping[$key] ?? null;
        }

        if (!is_object($mapping)) {
            return null;
        }

        $properties = get_object_vars($mapping);

        return $properties[$key] ?? null;
    }

    /**
     * @param array{
     *     ownerId: int|string,
     *     joinTable: string,
     *     joinColumn: string,
     *     inverseJoinColumn: string,
     *     ownerIdType: ?string,
     *     inverseIdType: ?string
     * } $queryContext
     *
     * @return list<mixed>
     */
    private function fetchOriginalCollectionRows(array $queryContext, EntityManagerInterface $em): array
    {
        $databaseOwnerId = $this->convertValueToDatabaseType($queryContext['ownerId'], $queryContext['ownerIdType'], $em);
        $queryBuilder = $em->getConnection()->createQueryBuilder()
            ->select($queryContext['inverseJoinColumn'])
            ->from($queryContext['joinTable'])
            ->where($queryContext['joinColumn'].' = :ownerId');

        if ($queryContext['ownerIdType'] !== null) {
            $queryBuilder->setParameter('ownerId', $databaseOwnerId, $queryContext['ownerIdType']);
        } else {
            $queryBuilder->setParameter('ownerId', $databaseOwnerId);
        }

        return $queryBuilder
            ->executeQuery()
            ->fetchFirstColumn();
    }

    /**
     * @param iterable<mixed> $rows
     *
     * @return array<int, int|string>
     */
    private function normalizeLoadedIdentifiers(iterable $rows, ?string $inverseIdType, EntityManagerInterface $em): array
    {
        $resolvedIds = [];
        foreach ($rows as $row) {
            $resolvedId = $this->normalizeLoadedIdentifier($row, $inverseIdType, $em);
            if ($resolvedId !== null) {
                $resolvedIds[] = $resolvedId;
            }
        }

        return $resolvedIds;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function resolveColumnType(ClassMetadata $metadata, string $referencedColumnName): ?string
    {
        try {
            $fieldName = $metadata->getFieldForColumn($referencedColumnName);

            return $metadata->getTypeOfField($fieldName);
        } catch (Throwable) {
            return null;
        }
    }

    private function convertValueToDatabaseType(
        int|string $value,
        ?string $type,
        EntityManagerInterface $em,
    ): mixed {
        if ($type === null) {
            return $value;
        }

        try {
            return $em->getConnection()->convertToDatabaseValue($value, $type);
        } catch (Throwable) {
            return $value;
        }
    }

    private function normalizeLoadedIdentifier(
        mixed $value,
        ?string $type,
        EntityManagerInterface $em,
    ): int|string|null {
        if ($type !== null) {
            try {
                $value = $em->getConnection()->convertToPHPValue($value, $type);
            } catch (Throwable) {
            }
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
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
     * @param iterable<mixed> $items
     *
     * @return array<int, int|string>
     */
    private function extractIdsFromIterable(iterable $items, EntityManagerInterface $em): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

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

        return array_values(array_filter($newIds, static fn ($id) => !in_array($id, $deletedIds, true)));
    }
}
