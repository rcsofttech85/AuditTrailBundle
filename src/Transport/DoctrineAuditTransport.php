<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

final class DoctrineAuditTransport implements AuditTransportInterface
{
    use PendingIdResolver;

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

        // Persist the audit log if it's not already managed
        if (!$em->contains($log)) {
            $em->persist($log);
            $em->flush();
        }


        $entityId = $this->resolveEntityId($log, $context);

        if (null !== $entityId && $log->getId()) {
            // Direct SQL update to avoid triggering another flush
            /** @var \Doctrine\ORM\Mapping\ClassMetadata<AuditLog> $meta */
            $meta = $em->getClassMetadata(AuditLog::class);
            $table = $meta->getTableName();
            $em->getConnection()->executeStatement(
                sprintf('UPDATE %s SET entity_id = ? WHERE id = ?', $table),
                [$entityId, $log->getId()]
            );
        }
    }
}
