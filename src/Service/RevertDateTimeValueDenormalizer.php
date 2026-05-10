<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

use function is_array;
use function is_string;
use function str_contains;

final readonly class RevertDateTimeValueDenormalizer
{
    public function denormalize(mixed $value, string $type): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        $dateTimeClass = $this->resolveDateTimeClass($type);

        if (is_array($value) && isset($value['date'])) {
            /** @var array<string, mixed> $value */
            return $this->denormalizeFromArray($value, $dateTimeClass);
        }

        if (is_string($value)) {
            return $this->denormalizeFromString($value, $dateTimeClass);
        }

        return null;
    }

    /**
     * @return class-string<DateTime>|class-string<DateTimeImmutable>
     */
    private function resolveDateTimeClass(string $type): string
    {
        return str_contains($type, 'immutable')
            ? DateTimeImmutable::class
            : DateTime::class;
    }

    /**
     * @param array<string, mixed>                                   $value
     * @param class-string<DateTime>|class-string<DateTimeImmutable> $dateTimeClass
     */
    private function denormalizeFromArray(array $value, string $dateTimeClass): ?DateTimeInterface
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
    private function denormalizeFromString(string $value, string $dateTimeClass): ?DateTimeInterface
    {
        try {
            return new $dateTimeClass($value);
        } catch (Exception) {
            return null;
        }
    }
}
