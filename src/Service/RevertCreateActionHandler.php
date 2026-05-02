<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;
use RuntimeException;

final readonly class RevertCreateActionHandler implements RevertActionHandlerInterface
{
    public function supports(AuditAction $action): bool
    {
        return $action === AuditAction::Create;
    }

    public function buildPlan(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan
    {
        if (!$force) {
            throw new RuntimeException('Reverting a creation (deleting the entity) requires --force.');
        }

        return RevertPlan::fromChanges(['action' => AuditAction::Delete->value]);
    }
}
