<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

#[AsDoctrineListener(event: 'postSoftDelete')]
final readonly class SoftDeleteAuditListener
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ChangeProcessorInterface $changeProcessor,
        private ScheduledAuditManagerInterface $auditManager,
    ) {
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function postSoftDelete(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        $objectManager = $args->getObjectManager();

        if (
            $entity instanceof AuditLog
            || !$this->auditManager->isEnabled()
        ) {
            return;
        }

        $changeSet = $objectManager->getUnitOfWork()->getEntityChangeSet($entity);
        if ($this->changeProcessor->determineUpdateAction($changeSet) !== AuditAction::SoftDelete) {
            return;
        }

        [$oldChanges, $newChanges] = $this->changeProcessor->extractChanges($entity, $changeSet);
        if ($oldChanges === [] && $newChanges === []) {
            return;
        }

        if (
            !$this->auditService->shouldAudit($entity)
            || !$this->auditService->shouldAudit($entity, AuditAction::SoftDelete, $newChanges)
        ) {
            return;
        }

        $oldData = $this->auditService->getEntityData($entity, [], $objectManager);
        foreach ($oldChanges as $field => $value) {
            $oldData[$field] = $value;
        }

        $this->auditManager->addPendingDeletion(
            $entity,
            $oldData,
            AuditAction::SoftDelete,
        );
    }
}
