<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

/**
 * @internal
 */
final class EntityIdResolver
{
    /**
     * @param array<string, mixed> $context
     */
    public static function resolve(AuditLogInterface $log, array $context): ?string
    {
        $currentId = $log->getEntityId();

        if ('pending' !== $currentId) {
            return (bool) ($context['is_insert'] ?? false) ? $currentId : null;
        }

        return self::resolveFromContext($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveFromContext(array $context): ?string
    {
        $entity = $context['entity'] ?? null;
        $em = $context['em'] ?? null;

        if (!\is_object($entity) || !$em instanceof EntityManagerInterface) {
            return null;
        }

        $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return self::formatIds($ids);
    }

    /**
     * @param array<string, mixed> $ids
     */
    private static function formatIds(array $ids): ?string
    {
        return match (\count($ids)) {
            0 => null,
            1 => (string) reset($ids),
            default => json_encode(array_values($ids), JSON_THROW_ON_ERROR),
        };
    }
}
