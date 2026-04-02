<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

use function array_filter;
use function array_values;
use function is_iterable;
use function is_string;
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

        foreach ($uow->getScheduledEntityDeletions() as $deletedEntity) {
            foreach ($this->buildRelatedEntityCollectionImpacts($deletedEntity, $em) as $impact) {
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

        return array_values($aggregated);
    }

    /**
     * @param list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}> $impacts
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractDeletedEntityAssociationChangesForOwner(object $owner, array $impacts): array
    {
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
     * @return list<array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}>
     */
    private function buildRelatedEntityCollectionImpacts(object $deletedEntity, EntityManagerInterface $em): array
    {
        $deletedId = $this->collectionIdExtractor->extractFromIterable([$deletedEntity], $em)[0] ?? AuditLogInterface::PENDING_ID;
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
}
