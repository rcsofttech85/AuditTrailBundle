<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use ReflectionMethod;
use ReflectionProperty;

final readonly class SoftDeleteHandler implements SoftDeleteHandlerInterface
{
    public function __construct(
        private EntityManagerResolver $entityManagerResolver,
        private string $softDeleteField = 'deletedAt',
    ) {
    }

    public function isSoftDeleted(object $entity): bool
    {
        return $this->readSoftDeleteValue($entity) !== null;
    }

    public function restoreSoftDeleted(object $entity): void
    {
        $this->writeSoftDeleteValue($entity, null);
    }

    private function readSoftDeleteValue(object $entity): mixed
    {
        $metadata = $this->resolveMetadata($entity);
        if ($metadata !== null && ($metadata->hasField($this->softDeleteField) || $metadata->hasAssociation($this->softDeleteField))) {
            return $metadata->getFieldValue($entity, $this->softDeleteField);
        }

        $getter = 'get'.$this->toMethodSuffix($this->softDeleteField);
        if (method_exists($entity, $getter)) {
            return new ReflectionMethod($entity, $getter)->invoke($entity);
        }

        $isser = 'is'.$this->toMethodSuffix($this->softDeleteField);
        if (method_exists($entity, $isser)) {
            return new ReflectionMethod($entity, $isser)->invoke($entity);
        }

        if (property_exists($entity, $this->softDeleteField)) {
            return new ReflectionProperty($entity, $this->softDeleteField)->getValue($entity);
        }

        return null;
    }

    private function writeSoftDeleteValue(object $entity, mixed $value): void
    {
        $metadata = $this->resolveMetadata($entity);
        if ($metadata !== null && ($metadata->hasField($this->softDeleteField) || $metadata->hasAssociation($this->softDeleteField))) {
            $metadata->setFieldValue($entity, $this->softDeleteField, $value);

            return;
        }

        $setter = 'set'.$this->toMethodSuffix($this->softDeleteField);
        if (method_exists($entity, $setter)) {
            new ReflectionMethod($entity, $setter)->invoke($entity, $value);

            return;
        }

        if (property_exists($entity, $this->softDeleteField)) {
            new ReflectionProperty($entity, $this->softDeleteField)->setValue($entity, $value);
        }
    }

    /**
     * @return ClassMetadata<object>|null
     */
    private function resolveMetadata(object $entity): ?ClassMetadata
    {
        $entityManager = $this->entityManagerResolver->resolveForObject($entity);
        if ($entityManager === null) {
            return null;
        }

        return $entityManager->getClassMetadata($entity::class);
    }

    private function toMethodSuffix(string $field): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $field)));
    }
}
