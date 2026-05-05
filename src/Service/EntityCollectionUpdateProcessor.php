<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;

final readonly class EntityCollectionUpdateProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditQueueManagerInterface $auditManager,
        private CollectionChangeResolver $collectionChangeResolver,
        private DeferredCollectionDetector $deferredCollectionDetector,
        private EntityAuditDispatchManager $dispatchManager,
    ) {
    }

    /**
     * @param iterable<object> $collectionUpdates
     */
    public function process(EntityManagerInterface $em, UnitOfWork $uow, iterable $collectionUpdates): void
    {
        foreach ($collectionUpdates as $collection) {
            if (!$this->collectionChangeResolver->isTrackableCollection($collection)) {
                continue;
            }

            $owner = $this->collectionChangeResolver->getCollectionOwner($collection);
            if ($owner === null || $uow->isScheduledForInsert($owner) || $uow->isScheduledForUpdate($owner)) {
                continue;
            }

            $fieldName = $collection->getMapping()->fieldName;
            $transition = $this->collectionChangeResolver->buildCollectionTransition($collection, $em);
            if ($transition === null) {
                continue;
            }

            $newValues = [$fieldName => $transition['new']];
            if (!$this->auditService->shouldAudit($owner, AuditAction::Update, $newValues)) {
                continue;
            }

            if ($this->deferredCollectionDetector->shouldDeferCollectionFieldMaterialization($owner, $fieldName, $em)) {
                $this->auditManager->schedulePendingAuditPlan(PendingAuditPlan::forDeferredCollections(
                    $owner,
                    AuditAction::Update,
                    [$fieldName => $transition['old']],
                    [],
                    [$fieldName],
                ));

                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $owner,
                AuditAction::Update,
                [$fieldName => $transition['old']],
                $newValues,
                [],
                $em,
            );

            $this->dispatchManager->dispatchOrSchedule($audit, $owner, $em, $uow, false);
        }
    }
}
