<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('audit_trail.revert_action_handler')]
interface RevertActionHandlerInterface
{
    public function supports(AuditAction $action): bool;

    public function buildPlan(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan;
}
