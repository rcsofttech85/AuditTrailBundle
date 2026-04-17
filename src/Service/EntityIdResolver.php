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
            $meta = $em->getClassMetadata($entity::class);
            $idFields = $meta->getIdentifierFieldNames();

            $ids = $this->collectIdsFromValues($idFields, $values);
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

        $idValues = [];
        foreach ($ids as $val) {
            $formatted = $this->formatId($val);
            if ($formatted !== null) {
                $idValues[] = $formatted;
            }
        }

        if ($idValues === []) {
            return null;
        }

        if (count($idValues) === 1) {
            return $idValues[0];
        }

        return json_encode($idValues, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEntityIds(object $entity, EntityManagerInterface $em): array
    {
        /** @var ClassMetadata<object>|null $meta */
        $meta = null;

        try {
            $meta = $em->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);
            if ($ids !== []) {
                /** @var array<string, mixed> $ids */
                return $ids;
            }
        } catch (Throwable) {
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
            try {
                $meta = $em->getClassMetadata($entity::class);
            } catch (Throwable) {
                return [];
            }
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
        }

        return [];
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

    /**
     * @param array<string>        $idFields
     * @param array<string, mixed> $values
     *
     * @return array<string>|null
     */
    private function collectIdsFromValues(array $idFields, array $values): ?array
    {
        $ids = [];
        foreach ($idFields as $idField) {
            if (!isset($values[$idField])) {
                return null;
            }
            $val = $values[$idField];
            $formatted = $this->formatId($val);
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
