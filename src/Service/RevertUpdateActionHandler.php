<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;
use RuntimeException;

final readonly class RevertUpdateActionHandler implements RevertActionHandlerInterface
{
    public function __construct(
        private RevertPlanBuilder $revertPlanBuilder,
    ) {
    }

    public function supports(AuditAction $action): bool
    {
        return $action === AuditAction::Update;
    }

    public function buildPlan(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan
    {
        $oldValues = $log->oldValues ?? [];
        if ($oldValues === []) {
            throw new RuntimeException('No old values found in audit log to revert to.');
        }

        return $this->revertPlanBuilder->build($entity, $oldValues, $dryRun);
    }
}
