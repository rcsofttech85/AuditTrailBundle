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
        $uow = $context['uow']; // This is now guaranteed to exist by the Subscriber fix

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

        // 1. Persist if not managed
        if (!$em->contains($log)) {
            $em->persist($log);
        }

        // Doctrine will pick this change up when the subscriber does the final flush.
        $entityId = $this->resolveEntityId($log, $context);

        if (null !== $entityId) {
            $log->setEntityId($entityId);
        }
    }

    public function supports(string $phase): bool
    {
        // Doctrine transport supports both phases
        return true;
    }
}
