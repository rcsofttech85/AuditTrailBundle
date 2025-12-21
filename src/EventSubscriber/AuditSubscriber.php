<?php

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class AuditSubscriber
{
    private array $pendingAudits = [];

    public function __construct(
        private readonly AuditService $auditService
    ) {}

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

            $this->persistAudit($em, $uow, $audit);

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

            $this->persistAudit($em, $uow, $audit);
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

            $this->persistAudit($em, $uow, $audit);
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
        $connection = $em->getConnection();

        foreach ($pendingAudits as $pending) {
            $entity = $pending['entity'];
            $audit = $pending['audit'];

            // Get the actual entity ID
            $meta = $em->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);


            $entityId = !empty($ids) ? implode('-', $ids) : 'pending';

            // Update audit log directly in database to avoid triggering another flush
            if ($entityId !== 'pending' && $audit->getId()) {
                $table = $em->getClassMetadata(AuditLog::class)->getTableName();
                $connection->executeStatement(
                    sprintf('UPDATE %s SET entity_id = ? WHERE id = ?', $table),
                    [$entityId, $audit->getId()]
                );
            }
        }

        // Clear pending audits
        $this->pendingAudits = [];
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

    private function persistAudit(EntityManagerInterface $em, UnitOfWork $uow, AuditLog $audit): void
    {
        $em->persist($audit);
        $uow->computeChangeSet(
            $em->getClassMetadata(AuditLog::class),
            $audit
        );
    }
}
