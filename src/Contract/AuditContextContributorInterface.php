<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('audit_trail.context_contributor')]
interface AuditContextContributorInterface
{
    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array<string, mixed>
     */
    public function contribute(object $entity, string $action, array $changeSet): array;
}
