<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use RuntimeException;

use function sprintf;

final readonly class RevertGuard
{
    public function __construct(
        private AuditIntegrityServiceInterface $integrityService,
        private AuditLogRepositoryInterface $repository,
    ) {
    }

    public function assertRevertable(AuditLog $log, bool $verifySignature): void
    {
        if ($verifySignature && $this->integrityService->isEnabled() && !$this->integrityService->verifySignature($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has been tampered with and cannot be reverted.', $log->id?->toRfc4122() ?? 'unknown'));
        }

        if ($this->repository->isReverted($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has already been reverted.', $log->id?->toRfc4122() ?? 'unknown'));
        }
    }
}
