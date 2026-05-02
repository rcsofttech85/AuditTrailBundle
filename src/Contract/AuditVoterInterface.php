<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('audit_trail.voter')]
interface AuditVoterInterface
{
    /**
     * @param array<string, mixed> $changeSet
     */
    public function vote(object $entity, AuditAction $action, array $changeSet): bool;
}
