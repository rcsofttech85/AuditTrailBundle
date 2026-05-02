<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Stringable;
use Throwable;

use function count;
use function is_object;
use function is_scalar;

use const JSON_THROW_ON_ERROR;

/**
 * @internal
 */
final class EntityIdResolver implements EntityIdResolverInterface
{
    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    #[Override]
    public function resolve(object $object, AuditTransportContext $context): ?string
    {
        if (!$object instanceof AuditLogInterface) {
            return null;
        }

        $currentId = $object->entityId;

        if ($currentId !== AuditLogInterface::PENDING_ID) {
            return $context->phase->isOnFlush() ? $currentId : null;
        }

        return $this->resolveFromContext($context);
    }

    #[Override]
    public function resolveFromEntity(object $entity, ?EntityManagerInterface $em = null): string
    {
        $resolvedId = null;
        $em ??= $this->entityManager;

        if ($em !== null) {
            try {
                $resolvedId = $this->resolveFromMetadata($entity, $em);
            } catch (Throwable) {
                // fallback
            }
        }

        if ($resolvedId !== null) {
            return $resolvedId;
        }

        $methodId = $this->resolveFromMethod($entity);
        if ($methodId !== null) {
            return $methodId;
        }

        return AuditLogInterface::PENDING_ID;
    }

    /**
     * @param array<string, mixed> $values
     */
    #[Override]
    public function resolveFromValues(object $entity, array $values, EntityManagerInterface $em): ?string
    {
        try {
            $meta = $this->tryGetClassMetadata($entity, $em);
            if ($meta === null) {
                return null;
            }

            $idFields = $meta->getIdentifierFieldNames();

            $ids = $this->collectIdsFromValues($idFields, $values, $em);
            if ($ids === null) {
                return null;
            }

            return 1 < count($ids)
                ? json_encode($ids, JSON_THROW_ON_ERROR)
                : ($ids[0] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveFromMetadata(object $entity, EntityManagerInterface $em): ?string
    {
        $ids = $this->extractEntityIds($entity, $em);

        if ($ids === []) {
            return null;
        }

        $idValues = $this->normalizeIdentifierValues($ids, $em);

        if ($idValues === []) {
            return null;
        }

        if (count($idValues) === 1) {
            return $idValues[0];
        }

        return json_encode($idValues, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $ids
     *
     * @return list<string>
     */
    private function normalizeIdentifierValues(array $ids, EntityManagerInterface $em): array
    {
        $formattedIds = [];

        foreach ($ids as $value) {
            $formatted = $this->normalizeIdentifierValue($value, $em);
            if ($formatted === null) {
                return [];
            }

            $formattedIds[] = $formatted;
        }

        return $formattedIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEntityIds(object $entity, EntityManagerInterface $em): array
    {
        $meta = $this->tryGetClassMetadata($entity, $em);
        if ($meta !== null) {
            $ids = $meta->getIdentifierValues($entity);
            if ($ids !== []) {
                /** @var array<string, mixed> $ids */
                return $ids;
            }
        }

        $uow = $em->getUnitOfWork();
        if ($uow->isInIdentityMap($entity)) {
            $ids = $uow->getEntityIdentifier($entity);
            if ($ids !== []) {
                /** @var array<string, mixed> $ids */
                return $ids;
            }
        }

        // Final fallback: Reflection
        if ($meta === null) {
            return [];
        }

        try {
            $idFields = $meta->getIdentifierFieldNames();
            $idValues = [];
            foreach ($idFields as $field) {
                $reflProp = $meta->getReflectionProperty($field);
                $val = $reflProp?->getValue($entity);
                if ($val !== null) {
                    $idValues[$field] = $val;
                }
            }
            if ($idValues !== []) {
                return $idValues;
            }
        } catch (Throwable) {
            // Reflection fallback failed; the caller will treat this entity as unresolved.
        }

        return [];
    }

    /**
     * @return ClassMetadata<object>|null
     */
    private function tryGetClassMetadata(object $entity, EntityManagerInterface $em): ?ClassMetadata
    {
        try {
            return $em->getClassMetadata($entity::class);
        } catch (Throwable) {
            // Metadata extraction is best-effort here; callers have additional fallbacks.
            return null;
        }
    }

    private function resolveFromMethod(object $entity): ?string
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }

        try {
            $id = $entity->getId();

            return $this->formatId($id);
        } catch (Throwable) {
            return null;
        }
    }

    private function formatId(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (is_scalar($id) || $id instanceof Stringable) {
            return (string) $id;
        }

        return null;
    }

    private function normalizeIdentifierValue(mixed $value, EntityManagerInterface $em): ?string
    {
        $formatted = $this->formatId($value);
        if ($formatted !== null) {
            return $formatted;
        }

        if (!is_object($value)) {
            return null;
        }

        try {
            $nestedIds = $this->extractEntityIds($value, $em);
        } catch (Throwable) {
            return null;
        }

        if ($nestedIds === []) {
            return null;
        }

        $normalizedNestedIds = $this->normalizeIdentifierValues($nestedIds, $em);
        if ($normalizedNestedIds === []) {
            return null;
        }

        return 1 < count($normalizedNestedIds)
            ? json_encode($normalizedNestedIds, JSON_THROW_ON_ERROR)
            : $normalizedNestedIds[0];
    }

    /**
     * @param array<string>        $idFields
     * @param array<string, mixed> $values
     *
     * @return array<string>|null
     */
    private function collectIdsFromValues(array $idFields, array $values, EntityManagerInterface $em): ?array
    {
        $ids = [];
        foreach ($idFields as $idField) {
            if (!isset($values[$idField])) {
                return null;
            }
            $val = $values[$idField];
            $formatted = $this->normalizeIdentifierValue($val, $em);
            if ($formatted === null) {
                return null;
            }

            $ids[] = $formatted;
        }

        return $ids;
    }

    private function resolveFromContext(AuditTransportContext $context): ?string
    {
        $entity = $context->entity;
        $em = $context->entityManager;

        if (!is_object($entity)) {
            return null;
        }

        return $this->resolveFromEntity($entity, $em);
    }
}
