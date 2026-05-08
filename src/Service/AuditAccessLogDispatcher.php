<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAccessAudit;
use Throwable;

final readonly class AuditAccessLogDispatcher
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditDispatcherInterface $dispatcher,
        private AuditAccessCooldownManager $cooldownManager,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function dispatch(PendingAccessAudit $pendingAccess): void
    {
        try {
            $context = [...$pendingAccess->context, 'level' => $pendingAccess->access->level];
            if ($pendingAccess->access->message !== null) {
                $context['message'] = $pendingAccess->access->message;
            }

            $audit = $this->auditService->createAuditLog(
                $pendingAccess->entity,
                AuditAction::Access,
                null,
                null,
                $context,
                $pendingAccess->entityManager,
            );

            if ($this->dispatcher->dispatch(
                $audit,
                $pendingAccess->entityManager,
                AuditPhase::PostLoad,
                null,
                $pendingAccess->entity,
            )) {
                return;
            }

            $this->cooldownManager->clearForRequest($pendingAccess->requestKey, $pendingAccess->context);
        } catch (Throwable $e) {
            $this->cooldownManager->clearForRequest($pendingAccess->requestKey, $pendingAccess->context);
            $this->logger?->error('Failed to log audit access', [
                'entity' => $pendingAccess->entity::class,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
