<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface ContextResolverInterface
{
    /**
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $extraContext
     *
     * @return array{
     *     userId: ?string,
     *     username: ?string,
     *     ipAddress: ?string,
     *     userAgent: ?string,
     *     context: array<string, mixed>
     * }
     */
    public function resolve(object $entity, string $action, array $newValues, array $extraContext): array;
}
