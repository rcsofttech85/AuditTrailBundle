<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Stringable;
use Throwable;

use function count;
use function is_object;
use function is_scalar;

use const JSON_THROW_ON_ERROR;

/**
 * @internal
 */
final class EntityIdResolver
{
    public const string PENDING_ID = 'pending';

    /**
     * @param array<string, mixed> $context
     */
    public static function resolve(AuditLogInterface $log, array $context): ?string
    {
        $currentId = $log->getEntityId();

        if ($currentId !== self::PENDING_ID) {
            return (bool) ($context['is_insert'] ?? false) ? $currentId : null;
        }

        return self::resolveFromContext($context);
    }

    public static function resolveFromEntity(object $entity, EntityManagerInterface $em): string
    {
        try {
            return self::resolveFromMetadata($entity, $em) ?? self::resolveFromMethod($entity) ?? self::PENDING_ID;
        } catch (Throwable) {
            return self::resolveFromMethod($entity) ?? self::PENDING_ID;
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function resolveFromValues(object $entity, array $values, EntityManagerInterface $em): ?string
    {
        try {
            $meta = $em->getClassMetadata($entity::class);
            $idFields = $meta->getIdentifierFieldNames();

            $ids = self::collectIdsFromValues($idFields, $values);
            if ($ids === null) {
                return null;
            }

            return count($ids) > 1
                ? json_encode($ids, JSON_THROW_ON_ERROR)
                : ($ids[0] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveFromMetadata(object $entity, EntityManagerInterface $em): ?string
    {
        $meta = $em->getClassMetadata($entity::class);
        $ids = $meta->getIdentifierValues($entity);

        if ($ids === []) {
            return null;
        }

        $idValues = [];
        foreach ($ids as $val) {
            $formatted = self::formatId($val);
            if ($formatted !== null) {
                $idValues[] = $formatted;
            }
        }

        if ($idValues === []) {
            return null;
        }

        return count($idValues) > 1
            ? json_encode($idValues, JSON_THROW_ON_ERROR)
            : reset($idValues);
    }

    private static function resolveFromMethod(object $entity): ?string
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }

        try {
            $id = $entity->getId();

            return self::formatId($id);
        } catch (Throwable) {
            return null;
        }
    }

    private static function formatId(mixed $id): ?string
    {
        if (is_scalar($id) || $id instanceof Stringable) {
            return (string) $id;
        }

        return null;
    }

    /**
     * @param array<string>        $idFields
     * @param array<string, mixed> $values
     *
     * @return array<string>|null
     */
    private static function collectIdsFromValues(array $idFields, array $values): ?array
    {
        $ids = [];
        foreach ($idFields as $idField) {
            if (!isset($values[$idField])) {
                return null;
            }
            $val = $values[$idField];
            $ids[] = self::formatId($val) ?? '';
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveFromContext(array $context): ?string
    {
        $entity = $context['entity'] ?? null;
        $em = $context['em'] ?? null;

        if (!is_object($entity) || !$em instanceof EntityManagerInterface) {
            return null;
        }

        return self::resolveFromEntity($entity, $em);
    }
}
