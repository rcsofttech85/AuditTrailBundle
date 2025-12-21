<?php

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class AuditSubscriber
{
    private array $pendingAudits = [];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly AuditTransportInterface $transport
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // INSERT - Store for postFlush ID update
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLog::ACTION_CREATE,
                null,
                $this->auditService->getEntityData($entity)
            );

            $this->transport->send($audit, [
                'phase' => 'on_flush',
                'em' => $em,
                'uow' => $uow
            ]);

            // Store for postFlush to update entity ID
            $this->pendingAudits[] = ['entity' => $entity, 'audit' => $audit];
        }

        // UPDATE
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            [$old, $new] = $this->extractChanges($changeSet);

            $audit = $this->auditService->createAuditLog($entity, AuditLog::ACTION_UPDATE, $old, $new);

            $this->transport->send($audit, [
                'phase' => 'on_flush',
                'em' => $em,
                'uow' => $uow
            ]);
        }

        // DELETE
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldProcessEntity($entity)) {
                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLog::ACTION_DELETE,
                $this->auditService->getEntityData($entity),
                null
            );

            $this->transport->send($audit, [
                'phase' => 'on_flush',
                'em' => $em,
                'uow' => $uow
            ]);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pendingAudits)) {
            return;
        }

        $pendingAudits = $this->pendingAudits;
        $this->pendingAudits = [];

        $em = $args->getObjectManager();

        foreach ($pendingAudits as $pending) {
            $entity = $pending['entity'];
            $audit = $pending['audit'];

            $this->transport->send($audit, [
                'phase' => 'post_flush',
                'em' => $em,
                'entity' => $entity
            ]);
        }
    }

    private function shouldProcessEntity(object $entity): bool
    {
        if ($entity instanceof AuditLog) {
            return false;
        }

        return $this->auditService->shouldAudit($entity);
    }

    private function extractChanges(array $changeSet): array
    {
        $old = [];
        $new = [];

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if ($oldValue === $newValue) {
                continue;
            }
            $old[$field] = $oldValue;
            $new[$field] = $newValue;
        }

        return [$old, $new];
    }
}

