<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;

final class DoctrineAuditTransport implements AuditTransportInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLogInterface $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;

        if ($phase === 'on_flush') {
            $this->handleOnFlush($log, $context);
        } elseif ($phase === 'post_flush') {
            $this->handlePostFlush($log, $context);
        }
    }

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    private function handleOnFlush(AuditLogInterface $log, array $context): void
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
    private function handlePostFlush(AuditLogInterface $log, array $context): void
    {
        /** @var EntityManagerInterface $em */
        $em = $context['em'];

        // 1. Persist if not managed
        if (!$em->contains($log)) {
            $em->persist($log);
        }

        // Doctrine will pick this change up when the subscriber does the final flush.
        $entityId = EntityIdResolver::resolve($log, $context);

        if ($entityId !== null) {
            $log->setEntityId($entityId);
        }

        if ($entityId !== null) {
            $log->setEntityId($entityId);
        }
    }

    public function supports(string $phase, array $context = []): bool
    {
        // Doctrine transport supports both phases
        return true;
    }
}
