<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeInterface;
use ReflectionClass;
use Stringable;

use function array_map;
use function is_array;
use function is_object;
use function method_exists;
use function sprintf;

final class RevertPreviewFormatter
{
    public function format(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            if ($value instanceof Stringable || method_exists($value, '__toString')) {
                return (string) $value;
            }

            $className = new ReflectionClass($value)->getShortName();

            if (method_exists($value, 'getId')) {
                /** @var mixed $id */
                $id = $value->getId();

                return sprintf('%s#%s', $className, (string) $id);
            }

            return $className;
        }

        if (is_array($value)) {
            return array_map($this->format(...), $value);
        }

        return $value;
    }
}
