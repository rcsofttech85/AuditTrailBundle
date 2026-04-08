<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

interface EntityIdResolverInterface
{
    public function resolveFromEntity(object $entity, ?EntityManagerInterface $em = null): string;

    /**
     * @param array<string, mixed> $values
     */
    public function resolveFromValues(object $entity, array $values, EntityManagerInterface $em): int|string|null;

    public function resolve(object $object, AuditTransportContext $context): ?string;
}
