<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

final class DoctrineAuditTransport implements AuditTransportInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLog $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;

        if ('on_flush' === $phase) {
            $this->handleOnFlush($log, $context);
        } elseif ('post_flush' === $phase) {
            $this->handlePostFlush($log, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function handleOnFlush(AuditLog $log, array $context): void
    {
        /** @var EntityManagerInterface $em */
        $em = $context['em'];
        /** @var UnitOfWork $uow */
        $uow = $context['uow'];

        $em->persist($log);
        $uow->computeChangeSet($em->getClassMetadata(AuditLog::class), $log);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function handlePostFlush(AuditLog $log, array $context): void
    {
        /** @var EntityManagerInterface $em */
        $em = $context['em'];

        // Only update if we have a valid ID and the entity ID was pending
        if ($log->id && 'pending' === $log->entityId) {
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
