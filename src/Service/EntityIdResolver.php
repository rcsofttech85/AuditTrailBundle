<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
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

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function resolve(object $object, array $context = []): ?string
    {
        if (!$object instanceof AuditLogInterface) {
            return null;
        }

        $currentId = $object->entityId;

        if ($currentId !== AuditLogInterface::PENDING_ID) {
            return (bool) ($context['is_insert'] ?? false) ? $currentId : null;
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

            return count($ids) > 1
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
            return reset($idValues);
        }

        return json_encode($idValues, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEntityIds(object $entity, EntityManagerInterface $em): array
    {
        if ($entity instanceof Proxy) {
            $ids = $em->getUnitOfWork()->getEntityIdentifier($entity);
            if ($ids !== []) {
                return $ids;
            }
        }

        return $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
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
            $ids[] = $this->formatId($val) ?? '';
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveFromContext(array $context): ?string
    {
        $entity = $context['entity'] ?? null;
        $em = $context['em'] ?? null;

        if (!is_object($entity) || !$em instanceof EntityManagerInterface) {
            return null;
        }

        return $this->resolveFromEntity($entity, $em);
    }
}
