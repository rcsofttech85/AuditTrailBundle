<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;

use function in_array;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;

final class RevertValueDenormalizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function denormalize(ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($metadata->hasField($field)) {
            $type = $metadata->getTypeOfField($field);

            if (
                in_array($type, [
                    'datetime',
                    'datetime_immutable',
                    'datetimetz',
                    'datetimetz_immutable',
                    'date',
                    'date_immutable',
                    'time',
                    'time_immutable',
                ], true)
            ) {
                return $this->denormalizeDateTime($value, $type);
            }
        }

        if ($metadata->hasAssociation($field)) {
            return $this->denormalizeAssociation($metadata, $field, $value);
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
    private function denormalizeDateTimeFromString(string $value, string $dateTimeClass): DateTimeInterface
    {
        return new $dateTimeClass($value);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function denormalizeAssociation(ClassMetadata $metadata, string $field, mixed $value): ?object
    {
        $targetClass = $metadata->getAssociationTargetClass($field);

        if (is_object($value) && is_a($value, $targetClass)) {
            return $value;
        }

        if (is_scalar($value) || is_array($value)) {
            /* @var class-string $targetClass */
            return $this->em->find($targetClass, $value);
        }

        return null;
    }
}
