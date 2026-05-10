<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadata;

use function is_array;
use function is_object;
use function is_scalar;

final readonly class RevertValueDenormalizer
{
    public function __construct(
        private EntityManagerResolver $entityManagerResolver,
        private RevertDateTimeValueDenormalizer $dateTimeDenormalizer,
        private EntityIdentifierNormalizer $identifierNormalizer,
    ) {
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function denormalize(ClassMetadata $metadata, string $field, mixed $value, bool $dryRun = false): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($metadata->hasField($field)) {
            return $this->denormalizeFieldValue($metadata, $field, $value);
        }

        if ($metadata->hasAssociation($field)) {
            return $this->denormalizeAssociation($metadata, $field, $value, $dryRun);
        }

        return $value;
    }

    public function valuesAreEqual(mixed $currentValue, mixed $newValue): bool
    {
        if ($currentValue instanceof DateTimeInterface && $newValue instanceof DateTimeInterface) {
            return $currentValue->format(DateTimeInterface::ATOM) === $newValue->format(DateTimeInterface::ATOM);
        }

        return $currentValue === $newValue;
    }

    /**
     * @param class-string<object> $class
     */
    public function normalizeEntityIdentifier(string $class, mixed $identifier): mixed
    {
        return $this->identifierNormalizer->normalize($class, $identifier);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function denormalizeFieldValue(ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        $type = (string) $metadata->getTypeOfField($field);

        return match ($type) {
            'datetime',
            'datetime_immutable',
            'datetimetz',
            'datetimetz_immutable',
            'date',
            'date_immutable',
            'time',
            'time_immutable' => $this->dateTimeDenormalizer->denormalize($value, $type),
            default => $value,
        };
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function denormalizeAssociation(ClassMetadata $metadata, string $field, mixed $value, bool $dryRun): ?object
    {
        /** @var class-string<object> $targetClass */
        $targetClass = $metadata->getAssociationTargetClass($field);

        if (is_object($value) && is_a($value, $targetClass)) {
            return $value;
        }

        if ($metadata->isCollectionValuedAssociation($field) && is_array($value)) {
            return new ArrayCollection($this->resolveAssociatedEntities($targetClass, $value, $dryRun));
        }

        if (!is_scalar($value) && !is_array($value)) {
            return null;
        }

        return $this->resolveAssociatedEntity($targetClass, $value, $dryRun);
    }

    /**
     * @param class-string<object> $targetClass
     * @param array<int, mixed>    $values
     *
     * @return list<object>
     */
    private function resolveAssociatedEntities(string $targetClass, array $values, bool $dryRun): array
    {
        $entities = [];
        foreach ($values as $identifier) {
            if (!is_scalar($identifier) && !is_array($identifier)) {
                continue;
            }

            $entity = $this->resolveAssociatedEntity($targetClass, $identifier, $dryRun);
            if ($entity !== null) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * @param class-string<object> $targetClass
     */
    private function resolveAssociatedEntity(string $targetClass, mixed $identifier, bool $dryRun): ?object
    {
        $normalizedIdentifier = $this->normalizeEntityIdentifier($targetClass, $identifier);
        $entityManager = $this->entityManagerResolver->requireForClass($targetClass);

        return $dryRun
            ? $entityManager->getReference($targetClass, $normalizedIdentifier)
            : $entityManager->find($targetClass, $normalizedIdentifier);
    }
}
