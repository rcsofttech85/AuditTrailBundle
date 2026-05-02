<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Stringable;

use function is_scalar;

final readonly class AuditLogAdminLocator
{
    public function __construct(
        private AuditLogRepositoryInterface $repository,
    ) {
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    public function loadFromContext(AdminContext $context): ?AuditLog
    {
        $request = $context->getRequest();
        $entityId = $request->query->getString('entityId');
        if ($entityId === '') {
            $attributeEntityId = $request->attributes->get('entityId');
            $entityId = is_scalar($attributeEntityId) || $attributeEntityId instanceof Stringable
                ? (string) $attributeEntityId
                : '';
        }

        if ($entityId === '') {
            return null;
        }

        /** @var AuditLog|null $auditLog */
        $auditLog = $this->repository->find($entityId);

        return $auditLog;
    }

    public function isUiRevertable(AuditLog $log, ?bool $isReverted = null): bool
    {
        if (!$log->action->isUiRevertable()) {
            return false;
        }

        if ($isReverted ?? $this->repository->isReverted($log)) {
            return false;
        }

        return !$this->repository->hasNewerStateChangingLogs($log);
    }

    public function isReverted(AuditLog $log): bool
    {
        return $this->repository->isReverted($log);
    }
}
