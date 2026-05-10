<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\TrackableCollectionInterface;

use function is_iterable;
use function spl_object_id;

final readonly class CollectionChangeIndexBuilder
{
    public function __construct(
        private CollectionIdExtractor $collectionIdExtractor,
        private JoinTableCollectionIdLoader $joinTableCollectionIdLoader,
    ) {
    }

    /**
     * @return array<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    public function build(EntityManagerInterface $em, UnitOfWork $uow, CollectionChangeResolver $resolver): array
    {
        $indexedChanges = [];
        /** @var array<class-string, ClassMetadata<object>> $metadataCache */
        $metadataCache = [];

        $this->mergeScheduledCollectionChangesIntoIndex($uow->getScheduledCollectionUpdates(), $em, $indexedChanges, $resolver);
        $this->mergeScheduledCollectionChangesIntoIndex($uow->getScheduledCollectionDeletions(), $em, $indexedChanges, $resolver);
        $this->mergeOwnerCollectionChangesIntoIndex($uow, $em, $metadataCache, $indexedChanges);

        return $indexedChanges;
    }

    /**
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     */
    private function mergeCollectionChangeIntoIndex(
        object $collection,
        EntityManagerInterface $em,
        array &$indexedChanges,
        CollectionChangeResolver $resolver,
    ): void {
        if (!$resolver->isTrackableCollection($collection)) {
            return;
        }

        $collectionOwner = $resolver->getCollectionOwner($collection);
        if ($collectionOwner === null) {
            return;
        }

        $transition = $resolver->buildCollectionTransition($collection, $em);
        if ($transition === null) {
            return;
        }

        $ownerId = spl_object_id($collectionOwner);
        $indexedChanges[$ownerId] ??= [
            'old' => [],
            'new' => [],
        ];
        $indexedChanges[$ownerId]['old'][$transition['field']] = $transition['old'];
        $indexedChanges[$ownerId]['new'][$transition['field']] = $transition['new'];
    }

    /**
     * @param iterable<object>                                                        $collections
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     */
    private function mergeScheduledCollectionChangesIntoIndex(
        iterable $collections,
        EntityManagerInterface $em,
        array &$indexedChanges,
        CollectionChangeResolver $resolver,
    ): void {
        foreach ($collections as $collection) {
            $this->mergeCollectionChangeIntoIndex($collection, $em, $indexedChanges, $resolver);
        }
    }

    /**
     * @param array<class-string, ClassMetadata<object>>                              $metadataCache
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     */
    private function mergeOwnerCollectionChangesIntoIndex(
        UnitOfWork $uow,
        EntityManagerInterface $em,
        array &$metadataCache,
        array &$indexedChanges,
    ): void {
        foreach ($uow->getScheduledEntityUpdates() as $owner) {
            $ownerChanges = $this->resolveOwnerCollectionChanges($owner, $uow, $em, $metadataCache, $indexedChanges);
            if ($ownerChanges === null) {
                continue;
            }

            $indexedChanges[spl_object_id($owner)] = $ownerChanges;
        }
    }

    /**
     * @param array<class-string, ClassMetadata<object>>                              $metadataCache
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $indexedChanges
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    private function resolveOwnerCollectionChanges(
        object $owner,
        UnitOfWork $uow,
        EntityManagerInterface $em,
        array &$metadataCache,
        array $indexedChanges,
    ): ?array {
        $ownerId = spl_object_id($owner);
        /** @var array<string, mixed> $oldValues */
        $oldValues = $indexedChanges[$ownerId]['old'] ?? [];
        /** @var array<string, mixed> $newValues */
        $newValues = $indexedChanges[$ownerId]['new'] ?? [];

        $this->mergeOriginalCollectionChangesForOwner($owner, $em, $uow, $metadataCache, $oldValues, $newValues);

        if ($oldValues === [] && $newValues === []) {
            return null;
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }

    /**
     * @param array<class-string, ClassMetadata<object>> $metadataCache
     * @param array<string, mixed>                       $oldValues
     * @param array<string, mixed>                       $newValues
     */
    private function mergeOriginalCollectionChangesForOwner(
        object $owner,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        array &$metadataCache,
        array &$oldValues,
        array &$newValues,
    ): void {
        $metadata = $metadataCache[$owner::class] ??= $em->getClassMetadata($owner::class);
        $originalData = $uow->getOriginalEntityData($owner);

        foreach ($metadata->getAssociationNames() as $associationName) {
            $transition = $this->resolveAssociationTransition(
                $owner,
                $associationName,
                $em,
                $metadata,
                $originalData,
                $oldValues,
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
     * @param array<string, mixed>  $originalData
     * @param array<string, mixed>  $oldValues
     *
     * @return array{old: array<int, int|string>, new: array<int, int|string>}|null
     */
    private function resolveAssociationTransition(
        object $owner,
        string $associationName,
        EntityManagerInterface $em,
        ClassMetadata $metadata,
        array $originalData,
        array $oldValues,
    ): ?array {
        if (isset($oldValues[$associationName]) || !$metadata->isCollectionValuedAssociation($associationName)) {
            return null;
        }

        $currentValue = $metadata->getFieldValue($owner, $associationName);
        if (!is_iterable($currentValue)) {
            return null;
        }

        $originalValue = $this->resolveOriginalCollectionValue($currentValue, $originalData, $associationName);
        if (!is_iterable($originalValue)) {
            return null;
        }

        return $this->resolveOriginalCollectionTransition(
            $owner,
            $associationName,
            $currentValue,
            $originalValue,
            $em,
        );
    }

    /**
     * @param array<string, mixed> $originalData
     */
    private function resolveOriginalCollectionValue(
        mixed $currentValue,
        array $originalData,
        string $associationName,
    ): mixed {
        if ($currentValue instanceof PersistentCollection || $currentValue instanceof TrackableCollectionInterface) {
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
        if ($currentValue instanceof PersistentCollection && !$currentValue->isInitialized()) {
            return null;
        }

        $originalIds = $this->collectionIdExtractor->extractFromIterable($originalValue, $em);
        $currentIds = $this->collectionIdExtractor->extractFromIterable($currentValue, $em);
        if ($originalIds === $currentIds && $currentIds === []) {
            $originalIds = $this->joinTableCollectionIdLoader->loadOriginalCollectionIdsFromDatabase($owner, $associationName, $em);
        }

        if ($originalIds === $currentIds) {
            return null;
        }

        return ['old' => $originalIds, 'new' => $currentIds];
    }
}
