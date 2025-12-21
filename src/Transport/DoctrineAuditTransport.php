<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

final class DoctrineAuditTransport implements AuditTransportInterface
{
    public function send(AuditLog $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;

        if ($phase === 'on_flush') {
            $this->handleOnFlush($log, $context);
        } elseif ($phase === 'post_flush') {
            $this->handlePostFlush($log, $context);
        }
    }

    private function handleOnFlush(AuditLog $log, array $context): void
    {
        /** @var EntityManagerInterface $em */
        $em = $context['em'];
        /** @var UnitOfWork $uow */
        $uow = $context['uow'];

        $em->persist($log);
        $uow->computeChangeSet($em->getClassMetadata(AuditLog::class), $log);
    }

    private function handlePostFlush(AuditLog $log, array $context): void
    {
        /** @var EntityManagerInterface $em */
        $em = $context['em'];

        // Only update if we have a valid ID and the entity ID was pending
        if ($log->id && $log->entityId === 'pending') {
            // We need the actual entity to get its ID now
            $entity = $context['entity'] ?? null;
            if (!$entity) {
                return;
            }

            $meta = $em->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);

            if (empty($ids)) {
                return;
            }

            $entityId = implode('-', $ids);

            // Direct SQL update to avoid triggering another flush
            $table = $em->getClassMetadata(AuditLog::class)->getTableName();
            $em->getConnection()->executeStatement(
                sprintf('UPDATE %s SET entity_id = ? WHERE id = ?', $table),
                [$entityId, $log->id]
            );
        }
    }
}
