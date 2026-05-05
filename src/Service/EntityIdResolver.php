<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Override;
use Psr\Log\LoggerInterface;
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
        private readonly ?LoggerInterface $logger = null,
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
            $resolvedId = $this->resolveFromMetadata($entity, $em);
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
        $meta = $this->tryGetClassMetadata($entity, $em);
        if ($meta === null) {
            return null;
        }

        $idFields = $meta->getIdentifierFieldNames();
        $ids = $this->collectIdsFromValues($entity, $idFields, $values, $em);
        if ($ids === null) {
            $this->logger?->debug('Unable to resolve identifier values from audit payload.', [
                'entity_class' => $entity::class,
                'identifier_fields' => $idFields,
            ]);

            return null;
        }

        return $this->formatResolvedIds(array_values($ids), $entity);
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

        return $this->formatResolvedIds($idValues, $entity);
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

        $nestedIds = $this->extractEntityIds($value, $em);
        if ($nestedIds === []) {
            return null;
        }

        $normalizedNestedIds = $this->normalizeIdentifierValues($nestedIds, $em);
        if ($normalizedNestedIds === []) {
            return null;
        }

        return $this->formatResolvedIds($normalizedNestedIds, $value);
    }

    /**
     * @param array<string>        $idFields
     * @param array<string, mixed> $values
     *
     * @return array<string>|null
     */
    private function collectIdsFromValues(object $entity, array $idFields, array $values, EntityManagerInterface $em): ?array
    {
        $ids = [];
        foreach ($idFields as $idField) {
            if (!isset($values[$idField])) {
                $this->logger?->debug('Identifier field is missing from audit payload values.', [
                    'entity_class' => $entity::class,
                    'identifier_field' => $idField,
                ]);

                return null;
            }
            $val = $values[$idField];
            $formatted = $this->normalizeIdentifierValue($val, $em);
            if ($formatted === null) {
                $this->logger?->debug('Identifier field value could not be normalized.', [
                    'entity_class' => $entity::class,
                    'identifier_field' => $idField,
                ]);

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

    /**
     * @param list<string> $ids
     */
    private function formatResolvedIds(array $ids, object $entity): ?string
    {
        if (count($ids) === 1) {
            return $ids[0];
        }

        try {
            return json_encode($ids, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->logger?->warning('Failed to encode composite identifier values.', [
                'entity_class' => $entity::class,
                'exception' => $this->normalizeExceptionContext($exception),
            ]);

            return null;
        }
    }

    /**
     * @return array{type: class-string<Throwable>, message: string}
     */
    private function normalizeExceptionContext(Throwable $exception): array
    {
        return [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }
}
