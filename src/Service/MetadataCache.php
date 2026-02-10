<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use SensitiveParameter;

use function array_key_exists;
use function count;

class MetadataCache
{
    private const int MAX_CACHE_SIZE = 100;

    /** @var array<string, Auditable|null> */
    private array $auditableCache = [];

    /** @var array<string, AuditCondition|null> */
    private array $conditionCache = [];

    /** @var array<string, array<string, string>> */
    private array $sensitiveFieldsCache = [];

    public function getAuditableAttribute(string $class): ?Auditable
    {
        if (array_key_exists($class, $this->auditableCache)) {
            return $this->auditableCache[$class];
        }

        $this->ensureCacheSize($this->auditableCache);
        $attribute = $this->resolveAttribute($class, Auditable::class);
        $this->auditableCache[$class] = $attribute;

        return $attribute;
    }

    public function getAuditCondition(string $class): ?AuditCondition
    {
        if (array_key_exists($class, $this->conditionCache)) {
            return $this->conditionCache[$class];
        }

        $this->ensureCacheSize($this->conditionCache);
        $attribute = $this->resolveAttribute($class, AuditCondition::class);
        $this->conditionCache[$class] = $attribute;

        return $attribute;
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(string $class): array
    {
        if (array_key_exists($class, $this->sensitiveFieldsCache)) {
            return $this->sensitiveFieldsCache[$class];
        }

        $this->ensureCacheSize($this->sensitiveFieldsCache);
        $fields = $this->resolveSensitiveFields($class);
        $this->sensitiveFieldsCache[$class] = $fields;

        return $fields;
    }

    /**
     * @param array<string, mixed> $cache
     */
    /**
     * @param array<string, mixed> $cache
     */
    private function ensureCacheSize(array &$cache): void
    {
        if (count($cache) >= self::MAX_CACHE_SIZE) {
            array_shift($cache);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attributeClass
     *
     * @return T|null
     */
    private function resolveAttribute(string $class, string $attributeClass): ?object
    {
        $currentClass = $class;
        while ($currentClass) {
            try {
                if (!class_exists($currentClass)) {
                    break;
                }
                $reflection = new ReflectionClass($currentClass);
                $attributes = $reflection->getAttributes($attributeClass);
                if ($attributes !== []) {
                    return $attributes[0]->newInstance();
                }
            } catch (ReflectionException) {
                break;
            }
            $currentClass = get_parent_class($currentClass);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function resolveSensitiveFields(string $class): array
    {
        /** @var array<string, string> $sensitiveFields */
        $sensitiveFields = [];
        try {
            if (!class_exists($class)) {
                return [];
            }
            $reflection = new ReflectionClass($class);

            $this->analyzeProperties($reflection, $sensitiveFields);
            $this->analyzeConstructorParameters($reflection, $sensitiveFields);
        } catch (ReflectionException) {
            // Ignore
        }

        return $sensitiveFields;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string, string>   $sensitiveFields
     */
    private function analyzeProperties(ReflectionClass $reflection, array &$sensitiveFields): void
    {
        foreach ($reflection->getProperties() as $property) {
            /** @var list<ReflectionAttribute<Sensitive>> $attributes */
            $attributes = $property->getAttributes(Sensitive::class);
            if ($attributes !== []) {
                /** @var Sensitive $sensitive */
                $sensitive = $attributes[0]->newInstance();
                $sensitiveFields[$property->getName()] = $sensitive->mask;
            }
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string, string>   $sensitiveFields
     */
    private function analyzeConstructorParameters(ReflectionClass $reflection, array &$sensitiveFields): void
    {
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $attributes = $param->getAttributes(SensitiveParameter::class);
                if ($attributes !== [] && $param->isPromoted() && !isset($sensitiveFields[$param->getName()])) {
                    $sensitiveFields[$param->getName()] = '**REDACTED**';
                }
            }
        }
    }
}
