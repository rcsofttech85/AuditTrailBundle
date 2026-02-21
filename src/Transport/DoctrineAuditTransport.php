<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

final class DoctrineAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private readonly EntityIdResolverInterface $idResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function send(AuditLog $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;

        if ($phase === 'on_flush') {
            $this->handleOnFlush($log, $context);
        } elseif ($phase === 'post_flush' || $phase === 'post_load' || $phase === 'batch_flush') {
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

        if (!$em->contains($log)) {
            $em->persist($log);
        }

        $entityId = $this->idResolver->resolve($log, $context);

        if ($entityId !== null) {
            $log->entityId = $entityId;
        }
    }

    #[Override]
    public function supports(string $phase, array $context = []): bool
    {
        return true;
    }
}
