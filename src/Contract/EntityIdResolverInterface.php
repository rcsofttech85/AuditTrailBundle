<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;

interface EntityIdResolverInterface
{
    public function resolveFromEntity(object $entity, ?EntityManagerInterface $em = null): string;

    /**
     * @param array<string, mixed> $values
     */
    public function resolveFromValues(object $entity, array $values, EntityManagerInterface $em): int|string|null;

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(object $object, array $context = []): ?string;
}
