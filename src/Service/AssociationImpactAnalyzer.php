<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

use function array_filter;
use function array_values;
use function is_iterable;
use function spl_object_id;

final readonly class AssociationImpactAnalyzer
{
    public function __construct(
        private CollectionIdExtractor $collectionIdExtractor,
        private CollectionTransitionMerger $collectionTransitionMerger,
    ) {
    }

    /**
     * @return list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}>
     */
    public function buildAggregatedDeletedAssociationImpacts(EntityManagerInterface $em, UnitOfWork $uow): array
    {
        /** @var array<string, array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $aggregated */
        $aggregated = [];
        /** @var array<class-string, ClassMetadata<object>> $metadataByClass */
        $metadataByClass = [];

        foreach ($uow->getScheduledEntityDeletions() as $deletedEntity) {
            $this->mergeDeletedEntityAssociationImpacts($aggregated, $metadataByClass, $deletedEntity, $em);
        }

        return array_values($aggregated);
    }

    /**
     * @param array<string, array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $aggregated
     * @param array<class-string, ClassMetadata<object>>                                                                    $metadataByClass
     */
    private function mergeDeletedEntityAssociationImpacts(
        array &$aggregated,
        array &$metadataByClass,
        object $deletedEntity,
        EntityManagerInterface $em,
    ): void {
        $deletedId = $this->resolveEntityId($deletedEntity, $em);
        if ($deletedId === AuditLogInterface::PENDING_ID) {
            return;
        }

        $metadata = $this->getClassMetadata($deletedEntity, $metadataByClass, $em);

        foreach ($metadata->getAssociationNames() as $associationName) {
            if (!$metadata->isCollectionValuedAssociation($associationName)) {
                continue;
            }

            foreach ($this->buildAssociationCollectionImpacts(
                $deletedEntity,
                $metadata,
                $metadataByClass,
                $associationName,
                $deletedId,
                $em,
            ) as $impact) {
                $key = spl_object_id($impact['entity']).':'.$impact['field'];
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = $impact;
                    continue;
                }

                $this->collectionTransitionMerger->mergeSingleFieldTransition(
                    $aggregated[$key]['old'],
                    $aggregated[$key]['new'],
                    $impact['old'],
                    $impact['new'],
                );
            }
        }
    }

    /**
     * @param ClassMetadata<object>                      $metadata
     * @param array<class-string, ClassMetadata<object>> $metadataByClass
     *
     * @return list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}>
     */
    private function buildAssociationCollectionImpacts(
        object $deletedEntity,
        ClassMetadata $metadata,
        array &$metadataByClass,
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
            $impact = $this->buildSingleRelatedEntityImpact(
                $relatedEntity,
                $counterpartField,
                $metadataByClass,
                $deletedId,
                $em,
            );
            if ($impact !== null) {
                $impacts[] = $impact;
            }
        }

        return $impacts;
    }

    private function resolveCounterpartFieldFromMapping(AssociationMapping $mapping): ?string
    {
        if ($mapping instanceof InverseSideMapping) {
            return $mapping->mappedBy;
        }

        if ($mapping instanceof OwningSideMapping) {
            return $mapping->inversedBy;
        }

        return null;
    }

    /**
     * @param array<class-string, ClassMetadata<object>> $metadataByClass
     *
     * @return array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}|null
     */
    private function buildSingleRelatedEntityImpact(
        object $relatedEntity,
        string $counterpartField,
        array &$metadataByClass,
        int|string $deletedId,
        EntityManagerInterface $em,
    ): ?array {
        $relatedMetadata = $this->getClassMetadata($relatedEntity, $metadataByClass, $em);
        $relatedCollection = $relatedMetadata->getFieldValue($relatedEntity, $counterpartField);
        if (!is_iterable($relatedCollection)) {
            return null;
        }

        $oldIds = $this->collectionIdExtractor->extractFromIterable($relatedCollection, $em);
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
     * @param array<class-string, ClassMetadata<object>> $metadataByClass
     *
     * @return ClassMetadata<object>
     */
    private function getClassMetadata(
        object $entity,
        array &$metadataByClass,
        EntityManagerInterface $em,
    ): ClassMetadata {
        return $metadataByClass[$entity::class] ??= $em->getClassMetadata($entity::class);
    }

    private function resolveEntityId(object $entity, EntityManagerInterface $em): int|string
    {
        return $this->collectionIdExtractor->extractFromIterable([$entity], $em)[0] ?? AuditLogInterface::PENDING_ID;
    }
}
