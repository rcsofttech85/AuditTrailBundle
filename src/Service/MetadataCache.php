<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Generator;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;
use ReflectionClass;
use ReflectionException;
use SensitiveParameter;

use function is_object;

class MetadataCache
{
    /** @var array<string, Auditable|null> */
    private array $auditableCache = [];

    /** @var array<string, AuditCondition|null> */
    private array $conditionCache = [];

    /** @var array<string, array<string, string>> */
    private array $sensitiveFieldsCache = [];

    /** @var array<string, AuditAccess|null> */
    private array $accessCache = [];

    public function getAuditableAttribute(string|object $class): ?Auditable
    {
        /** @var class-string $className */
        $className = is_object($class) ? $class::class : $class;

        return $this->auditableCache[$className] ??= $this->resolveAttribute($className, Auditable::class);
    }

    public function getAuditAccessAttribute(string|object $class): ?AuditAccess
    {
        /** @var class-string $className */
        $className = is_object($class) ? $class::class : $class;

        return $this->accessCache[$className] ??= $this->resolveAttribute($className, AuditAccess::class);
    }

    public function getAuditCondition(string|object $class): ?AuditCondition
    {
        /** @var class-string $className */
        $className = is_object($class) ? $class::class : $class;

        return $this->conditionCache[$className] ??= $this->resolveAttribute($className, AuditCondition::class);
    }

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(string|object $class): array
    {
        /** @var class-string $className */
        $className = is_object($class) ? $class::class : $class;

        return $this->sensitiveFieldsCache[$className] ??= $this->resolveSensitiveFields($className);
    }

    /**
     * @template T of object
     *
     * @param class-string    $class
     * @param class-string<T> $attributeClass
     *
     * @return T|null
     */
    private function resolveAttribute(string $class, string $attributeClass): ?object
    {
        try {
            foreach ($this->getClassHierarchy($class) as $reflection) {
                $attributes = $reflection->getAttributes($attributeClass);
                if ($attributes !== []) {
                    return $attributes[0]->newInstance();
                }
            }
        } catch (ReflectionException) {
        }

        return null;
    }

    /**
     * @param class-string $class
     *
     * @return Generator<int, ReflectionClass<object>>
     */
    private function getClassHierarchy(string $class): Generator
    {
        $current = new ReflectionClass($class);

        while ($current !== false) {
            yield $current;
            $current = $current->getParentClass();
        }
    }

    /**
     * @param class-string $class
     *
     * @return array<string, string>
     */
    private function resolveSensitiveFields(string $class): array
    {
        try {
            $reflection = new ReflectionClass($class);

            return [
                ...$this->resolveConstructorSensitiveFields($reflection),
                ...$this->resolvePropertySensitiveFields($reflection),
            ];
        } catch (ReflectionException) {
            return [];
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, string>
     */
    private function resolvePropertySensitiveFields(ReflectionClass $reflection): array
    {
        $fields = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Sensitive::class);
            if ($attributes !== []) {
                $fields[$property->getName()] = $attributes[0]->newInstance()->mask;
            }
        }

        return $fields;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, string>
     */
    private function resolveConstructorSensitiveFields(ReflectionClass $reflection): array
    {
        $fields = [];
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $fields;
        }

        foreach ($constructor->getParameters() as $param) {
            $attributes = $param->getAttributes(SensitiveParameter::class);
            if ($attributes !== [] && $param->isPromoted()) {
                $fields[$param->getName()] = '**REDACTED**';
            }
        }

        return $fields;
    }
}
