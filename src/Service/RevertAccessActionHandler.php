<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;

final readonly class RevertAccessActionHandler implements RevertActionHandlerInterface
{
    public function supports(AuditAction $action): bool
    {
        return $action === AuditAction::Access;
    }

    public function buildPlan(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan
    {
        return RevertPlan::fromChanges([]);
    }
}
