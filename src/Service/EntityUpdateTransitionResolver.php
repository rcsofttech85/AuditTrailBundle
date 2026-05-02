<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;

use function is_array;

final readonly class EntityUpdateTransitionResolver
{
    public function __construct(
        private ChangeProcessorInterface $changeProcessor,
        private DeletedAssociationImpactResolver $deletedAssociationImpactResolver,
        private CollectionChangeResolver $collectionChangeResolver,
        private CollectionTransitionMerger $collectionTransitionMerger,
    ) {
    }

    /**
     * @param list<AssociationImpact> $deletedAssociationImpacts
     *
     * @return array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>>
     */
    public function indexDeletedAssociationImpacts(array $deletedAssociationImpacts): array
    {
        return $this->deletedAssociationImpactResolver->indexByOwner($deletedAssociationImpacts);
    }

    /**
     * @return array<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    public function indexCollectionChanges(EntityManagerInterface $em, UnitOfWork $uow): array
    {
        return $this->collectionChangeResolver->extractCollectionChangesIndexedByOwner($em, $uow);
    }

    /**
     * @param array<int, array<string, array{old: array<int, int|string>, new: array<int, int|string>}>> $deletedAssociationImpactsByOwner
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}>                    $collectionChangesByOwner
     *
     * @return array{
     *     old: array<string, mixed>,
     *     new: array<string, mixed>,
     *     action: AuditAction
     * }
     */
    public function resolve(
        object $entity,
        UnitOfWork $uow,
        array $deletedAssociationImpactsByOwner,
        array $collectionChangesByOwner,
    ): array {
        $changeSet = $uow->getEntityChangeSet($entity);
        [$old, $new] = $this->changeProcessor->extractChanges($entity, $changeSet);
        [$collectionOld, $collectionNew] = $this->collectionChangeResolver->extractIndexedCollectionChangesForOwner(
            $entity,
            $collectionChangesByOwner,
        );
        [$deletedAssocOld, $deletedAssocNew] = $this->deletedAssociationImpactResolver->extractChangesForOwner(
            $entity,
            $deletedAssociationImpactsByOwner,
        );

        $this->mergeFieldTransitions($old, $new, $collectionOld, $collectionNew);
        $this->mergeFieldTransitions($old, $new, $deletedAssocOld, $deletedAssocNew);

        if ($old === [] && $new === []) {
            return [
                'old' => $old,
                'new' => $new,
                'action' => AuditAction::Update,
            ];
        }

        return [
            'old' => $old,
            'new' => $new,
            'action' => $this->changeProcessor->determineUpdateAction($changeSet),
        ];
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
}
