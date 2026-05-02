<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;

final readonly class RevertSoftDeleteActionHandler implements RevertActionHandlerInterface
{
    public function __construct(
        private SoftDeleteHandlerInterface $softDeleteHandler,
    ) {
    }

    public function supports(AuditAction $action): bool
    {
        return $action === AuditAction::SoftDelete;
    }

    public function buildPlan(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan
    {
        if ($this->softDeleteHandler->isSoftDeleted($entity)) {
            return new RevertPlan(['action' => AuditAction::Restore->value], restoreSoftDelete: !$dryRun);
        }

        return RevertPlan::fromChanges(['info' => 'Entity is not soft-deleted.']);
    }
}
