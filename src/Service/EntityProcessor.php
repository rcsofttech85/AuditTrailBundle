<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;

use function array_key_exists;
use function is_array;

final readonly class EntityProcessor implements EntityProcessorInterface
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ChangeProcessorInterface $changeProcessor,
        private ScheduledAuditManagerInterface $auditManager,
        private AssociationImpactAnalyzer $associationImpactAnalyzer,
        private CollectionChangeResolver $collectionChangeResolver,
        private EntityAuditDispatchManager $dispatchManager,
        private bool $enableHardDelete = true,
        ?EntityUpdateTransitionResolver $updateTransitionResolver = null,
        ?DeletedAssociationImpactResolver $deletedAssociationImpactResolver = null,
        ?CollectionTransitionMerger $collectionTransitionMerger = null,
    ) {
        $this->updateTransitionResolver = $updateTransitionResolver ?? new EntityUpdateTransitionResolver(
            $this->changeProcessor,
            $deletedAssociationImpactResolver ?? new DeletedAssociationImpactResolver(),
            $this->collectionChangeResolver,
            $collectionTransitionMerger ?? new CollectionTransitionMerger(),
        );
    }

    private EntityUpdateTransitionResolver $updateTransitionResolver;

    #[Override]
    public function processInsertions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $data = $this->auditService->getEntityData($entity, [], $em);
            if (!$this->auditService->shouldAudit($entity, AuditAction::Create, $data)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditAction::Create,
                null,
                $data,
                [],
                $em,
            );
            $this->dispatchManager->dispatchOrSchedule($audit, $entity, $em, $uow, true);
        }
    }

    #[Override]
    /**
     * @param list<AssociationImpact>|null $deletedAssociationImpacts
     */
    public function processUpdates(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $deletedAssociationImpacts ??= $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($em, $uow);
        $deletedAssociationImpactsByOwner = $this->updateTransitionResolver->indexDeletedAssociationImpacts($deletedAssociationImpacts);
        $collectionChangesByOwner = $this->updateTransitionResolver->indexCollectionChanges($em, $uow);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $transition = $this->updateTransitionResolver->resolve(
                $entity,
                $uow,
                $deletedAssociationImpactsByOwner,
                $collectionChangesByOwner,
            );
            $old = $transition['old'];
            $new = $transition['new'];

            if ($old === [] && $new === []) {
                continue;
            }

            $action = $transition['action'];

            if (!$this->auditService->shouldAudit($entity, $action, $new)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog($entity, $action, $old, $new, [], $em);
            $this->dispatchManager->dispatchOrSchedule($audit, $entity, $em, $uow, false);
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

            if (!$this->auditService->shouldAudit($owner, AuditAction::Update, $newValues)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $owner,
                AuditAction::Update,
                $oldValues,
                $newValues,
                [],
                $em,
            );
            $this->dispatchManager->dispatchOrSchedule($audit, $owner, $em, $uow, false);
        }
    }

    #[Override]
    /**
     * @param list<AssociationImpact>|null $deletedAssociationImpacts
     */
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

            $action = $this->resolveDeletionAction($entity, $em, $uow);
            if ($action === null) {
                continue;
            }

            $this->auditManager->addPendingDeletion(
                $entity,
                $this->auditService->getEntityData($entity, [], $em),
                $em->contains($entity),
                $action,
            );
        }
    }

    private function shouldProcessEntity(object $entity): bool
    {
        return !$entity instanceof AuditLog && $this->auditService->shouldAudit($entity);
    }

    private function resolveDeletionAction(object $entity, EntityManagerInterface $em, UnitOfWork $uow): ?AuditAction
    {
        $changeSet = $uow->getEntityChangeSet($entity);

        if ($this->isSoftDeleteChangeSet($changeSet)) {
            return null;
        }

        return $this->changeProcessor->determineDeletionAction($em, $entity, $this->enableHardDelete);
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
     * @param list<AssociationImpact> $impacts
     */
    private function processRelatedEntityCollectionImpacts(
        array $impacts,
        EntityManagerInterface $em,
        UnitOfWork $uow,
    ): void {
        foreach ($impacts as $impact) {
            $relatedEntity = $impact->entity;

            if ($this->isScheduledForInsertion($relatedEntity, $uow) || $this->isScheduledForUpdate($relatedEntity, $uow)) {
                continue;
            }

            $newValues = [$impact->field => $impact->new];
            if (!$this->auditService->shouldAudit($relatedEntity, AuditAction::Update, $newValues)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $relatedEntity,
                AuditAction::Update,
                [$impact->field => $impact->old],
                $newValues,
                [],
                $em,
            );
            $this->dispatchManager->dispatchOrSchedule($audit, $relatedEntity, $em, $uow, false);
        }
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
        return $this->changeProcessor->determineUpdateAction($changeSet) === AuditAction::SoftDelete;
    }
}
