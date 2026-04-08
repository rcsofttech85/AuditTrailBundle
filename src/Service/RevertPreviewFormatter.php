<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeInterface;
use ReflectionClass;
use Stringable;
use Traversable;

use function array_map;
use function explode;
use function gettype;
use function is_array;
use function is_object;
use function is_scalar;
use function iterator_to_array;
use function method_exists;
use function preg_replace;
use function sprintf;

final class RevertPreviewFormatter
{
    /** @var array<class-string, string> */
    private array $shortClassNames = [];

    public function format(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Traversable) {
            return array_map($this->format(...), iterator_to_array($value, false));
        }

        if (is_array($value)) {
            return array_map($this->format(...), $value);
        }

        if (is_object($value)) {
            if ($value instanceof Stringable || method_exists($value, '__toString')) {
                return (string) $value;
            }

            $className = $this->shortClassNames[$value::class] ??= $this->normalizeClassName(new ReflectionClass($value)->getShortName());
            $label = $this->extractObjectLabel($value);
            $id = $this->extractObjectId($value);

            if ($id !== null) {
                $formattedId = is_scalar($id) || $id instanceof Stringable ? (string) $id : gettype($id);

                return $label !== null
                    ? sprintf('%s#%s (%s)', $className, $formattedId, $label)
                    : sprintf('%s#%s', $className, $formattedId);
            }

            return $label !== null
                ? sprintf('%s (%s)', $className, $label)
                : $className;
        }

        return $value;
    }

    private function extractObjectLabel(object $value): ?string
    {
        foreach (['Label', 'Name', 'Title'] as $suffix) {
            $label = $this->extractObjectStringValue($value, $suffix);
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    private function extractObjectStringValue(object $value, string $suffix): ?string
    {
        return $this->stringifyObjectValue($this->resolveObjectStringValue($value, $suffix))
            ?? $this->stringifyObjectValue($this->extractPublicPropertyValue($value, strtolower($suffix)));
    }

    private function resolveObjectStringValue(object $value, string $suffix): mixed
    {
        return match ($suffix) {
            'Label' => method_exists($value, 'getLabel') ? $value->getLabel() : null,
            'Name' => method_exists($value, 'getName') ? $value->getName() : null,
            'Title' => method_exists($value, 'getTitle') ? $value->getTitle() : null,
            default => null,
        };
    }

    private function stringifyObjectValue(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof Stringable) {
            return null;
        }

        return (string) $value;
    }

    private function extractObjectId(object $value): mixed
    {
        if (method_exists($value, 'getId')) {
            return $value->getId();
        }

        return $this->extractPublicPropertyValue($value, 'id');
    }

    private function extractPublicPropertyValue(object $value, string $property): mixed
    {
        $reflectionClass = new ReflectionClass($value);
        if (!$reflectionClass->hasProperty($property)) {
            return null;
        }

        $reflectionProperty = $reflectionClass->getProperty($property);
        if (!$reflectionProperty->isPublic()) {
            return null;
        }

        if ($reflectionProperty->isInitialized($value) === false) {
            return null;
        }

        return $reflectionProperty->getValue($value);
    }

    private function normalizeClassName(string $className): string
    {
        if (str_starts_with($className, 'class@anonymous')) {
            return 'Anonymous';
        }

        $sanitized = preg_replace('/\x00.*/', '', $className);

        return $sanitized !== null && $sanitized !== '' ? explode('@', $sanitized)[0] : 'Object';
    }
}
