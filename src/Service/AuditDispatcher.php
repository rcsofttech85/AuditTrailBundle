<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Throwable;

readonly class AuditDispatcher
{
    public function __construct(
        private AuditTransportInterface $transport,
        private AuditIntegrityServiceInterface $integrityService,
        private ?LoggerInterface $logger = null,
        private bool $failOnTransportError = false,
        private bool $fallbackToDatabase = true,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supports(string $phase, array $context = []): bool
    {
        return $this->transport->supports($phase, $context);
    }

    public function dispatch(
        AuditLogInterface $audit,
        EntityManagerInterface $em,
        string $phase,
        ?UnitOfWork $uow = null,
    ): bool {
        $context = [
            'phase' => $phase,
            'em' => $em,
        ];

        if ($uow instanceof UnitOfWork) {
            $context['uow'] = $uow;
        }

        if ($this->integrityService->isEnabled()) {
            $audit->setSignature($this->integrityService->generateSignature($audit));
        }

        if ($this->safeSend($audit, $context)) {
            return true;
        }

        $this->persistFallback($audit, $em, $phase);

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function safeSend(AuditLogInterface $audit, array $context): bool
    {
        try {
            $this->transport->send($audit, $context);

            return true;
        } catch (Throwable $e) {
            $this->logger?->error('Failed to send audit to transport', [
                'exception' => $e->getMessage(),
                'audit_action' => $audit->getAction(),
            ]);

            if ($this->failOnTransportError) {
                throw $e;
            }

            return false;
        }
    }

    private function persistFallback(
        AuditLogInterface $audit,
        EntityManagerInterface $em,
        string $phase,
    ): void {
        if (!$this->fallbackToDatabase || !$em->isOpen()) {
            return;
        }

        try {
            if ($em->contains($audit)) {
                return;
            }

            $em->persist($audit);

            if ($phase === 'on_flush') {
                $em->getUnitOfWork()->computeChangeSet(
                    $em->getClassMetadata(AuditLog::class),
                    $audit
                );
            }
        } catch (Throwable $e) {
            $this->logger?->critical('Failed to persist audit log to database fallback', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
