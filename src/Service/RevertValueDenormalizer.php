<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function count;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;

use const JSON_THROW_ON_ERROR;

final class RevertValueDenormalizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
            $type = $metadata->getTypeOfField($field);

            return match ($type) {
                'datetime',
                'datetime_immutable',
                'datetimetz',
                'datetimetz_immutable',
                'date',
                'date_immutable',
                'time',
                'time_immutable' => $this->denormalizeDateTime($value, $type),
                default => $value,
            };
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

    private function denormalizeDateTime(mixed $value, string $type): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        $isImmutable = str_contains($type, 'immutable');
        /** @var class-string<DateTime>|class-string<DateTimeImmutable> $dateTimeClass */
        $dateTimeClass = $isImmutable ? DateTimeImmutable::class : DateTime::class;

        if (is_array($value) && isset($value['date'])) {
            /** @var array<string, mixed> $value */
            return $this->denormalizeDateTimeFromArray($value, $dateTimeClass);
        }

        if (is_string($value)) {
            return $this->denormalizeDateTimeFromString($value, $dateTimeClass);
        }

        return null;
    }

    /**
     * @param array<string, mixed>                                   $value
     * @param class-string<DateTime>|class-string<DateTimeImmutable> $dateTimeClass
     */
    private function denormalizeDateTimeFromArray(array $value, string $dateTimeClass): ?DateTimeInterface
    {
        try {
            $timezone = isset($value['timezone']) && is_string($value['timezone']) ? $value['timezone'] : 'UTC';
            $date = isset($value['date']) && is_string($value['date']) ? $value['date'] : 'now';

            return new $dateTimeClass($date, new DateTimeZone($timezone));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param class-string<DateTime>|class-string<DateTimeImmutable> $dateTimeClass
     */
    private function denormalizeDateTimeFromString(string $value, string $dateTimeClass): ?DateTimeInterface
    {
        try {
            return new $dateTimeClass($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function denormalizeAssociation(ClassMetadata $metadata, string $field, mixed $value, bool $dryRun): ?object
    {
        $targetClass = $metadata->getAssociationTargetClass($field);

        if (is_object($value) && is_a($value, $targetClass)) {
            return $value;
        }

        if ($metadata->isCollectionValuedAssociation($field) && is_array($value)) {
            $entities = [];
            foreach ($value as $identifier) {
                if (!is_scalar($identifier) && !is_array($identifier)) {
                    continue;
                }

                $normalizedIdentifier = $this->normalizeEntityIdentifier($targetClass, $identifier);

                /** @var class-string $targetClass */
                $entity = $dryRun
                    ? $this->em->getReference($targetClass, $normalizedIdentifier)
                    : $this->em->find($targetClass, $normalizedIdentifier);

                if ($entity !== null) {
                    $entities[] = $entity;
                }
            }

            return new ArrayCollection($entities);
        }

        if (is_scalar($value) || is_array($value)) {
            $normalizedIdentifier = $this->normalizeEntityIdentifier($targetClass, $value);

            /** @var class-string $targetClass */
            if ($dryRun) {
                return $this->em->getReference($targetClass, $normalizedIdentifier);
            }

            return $this->em->find($targetClass, $normalizedIdentifier);
        }

        return null;
    }

    /**
     * @param class-string<object> $class
     */
    public function normalizeEntityIdentifier(string $class, mixed $identifier): mixed
    {
        try {
            $targetMetadata = $this->em->getClassMetadata($class);
        } catch (Throwable) {
            return $identifier;
        }

        return $this->normalizeAssociationIdentifier($targetMetadata, $identifier);
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function normalizeAssociationIdentifier(ClassMetadata $targetMetadata, mixed $identifier): mixed
    {
        $identifierFields = array_values($targetMetadata->getIdentifierFieldNames());

        if ($identifierFields === []) {
            return $identifier;
        }

        if ($this->isSingleIdentifierValue($identifierFields, $identifier)) {
            return $this->normalizeSingleIdentifierValue($targetMetadata, $identifierFields[0], $identifier);
        }

        $identifier = $this->decodeCompositeIdentifierIfNeeded($identifierFields, $identifier);

        if (!is_array($identifier)) {
            return $identifier;
        }

        return $this->normalizeCompositeIdentifier($targetMetadata, $identifierFields, $identifier);
    }

    /**
     * @param list<string> $identifierFields
     */
    private function isSingleIdentifierValue(array $identifierFields, mixed $identifier): bool
    {
        return !is_array($identifier) && count($identifierFields) === 1;
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function normalizeSingleIdentifierValue(
        ClassMetadata $targetMetadata,
        string $identifierField,
        mixed $identifier,
    ): mixed {
        return $this->normalizeIdentifierValue(
            $this->resolveIdentifierFieldType($targetMetadata, $identifierField),
            $identifier
        );
    }

    /**
     * @param list<string> $identifierFields
     */
    private function decodeCompositeIdentifierIfNeeded(array $identifierFields, mixed $identifier): mixed
    {
        if (!is_string($identifier)) {
            return $identifier;
        }

        $decodedIdentifier = $this->decodeCompositeIdentifier($identifierFields, $identifier);

        return is_array($decodedIdentifier) ? $decodedIdentifier : $identifier;
    }

    /**
     * @param ClassMetadata<object>                  $targetMetadata
     * @param list<string>                           $identifierFields
     * @param array<string, mixed>|array<int, mixed> $identifier
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    private function normalizeCompositeIdentifier(
        ClassMetadata $targetMetadata,
        array $identifierFields,
        array $identifier,
    ): array {
        $normalizedIdentifier = $identifier;
        foreach ($identifierFields as $identifierField) {
            if (!array_key_exists($identifierField, $normalizedIdentifier)) {
                continue;
            }

            $normalizedIdentifier[$identifierField] = $this->normalizeIdentifierValue(
                $this->resolveIdentifierFieldType($targetMetadata, $identifierField),
                $normalizedIdentifier[$identifierField]
            );
        }

        return $normalizedIdentifier;
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function resolveIdentifierFieldType(ClassMetadata $targetMetadata, string $identifierField): ?string
    {
        try {
            return $targetMetadata->getTypeOfField($identifierField);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeIdentifierValue(?string $type, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return match ($type) {
                'uuid' => Uuid::fromString($value),
                'ulid' => Ulid::fromString($value),
                default => $value,
            };
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param list<string> $identifierFields
     *
     * @return array<string, mixed>|null
     */
    private function decodeCompositeIdentifier(array $identifierFields, string $identifier): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($identifier, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        if (!array_is_list($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        if (count($decoded) !== count($identifierFields)) {
            return null;
        }

        $mapped = [];
        foreach ($identifierFields as $index => $field) {
            $mapped[$field] = $decoded[$index];
        }

        return $mapped;
    }
}
