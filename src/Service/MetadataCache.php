<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;

class MetadataCache
{
    private const int MAX_CACHE_SIZE = 100;

    /** @var array<string, Auditable|null> */
    private array $auditableCache = [];

    /** @var array<string, array<string, string>> */
    private array $sensitiveFieldsCache = [];

    public function getAuditableAttribute(string $class): ?Auditable
    {
        if (\array_key_exists($class, $this->auditableCache)) {
            return $this->auditableCache[$class];
        }

        $this->ensureCacheSize($this->auditableCache);
        $attribute = $this->resolveAuditableAttribute($class);
        $this->auditableCache[$class] = $attribute;

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
        if (\array_key_exists($class, $this->sensitiveFieldsCache)) {
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
        if (\count($cache) >= self::MAX_CACHE_SIZE) {
            array_shift($cache);
        }
    }

    private function resolveAuditableAttribute(string $class): ?Auditable
    {
        $currentClass = $class;
        while ($currentClass) {
            try {
                if (!class_exists($currentClass)) {
                    break;
                }
                $reflection = new \ReflectionClass($currentClass);
                /** @var list<\ReflectionAttribute<Auditable>> $attributes */
                $attributes = $reflection->getAttributes(Auditable::class);
                if ([] !== $attributes) {
                    return $attributes[0]->newInstance();
                }
            } catch (\ReflectionException) {
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
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                /** @var list<\ReflectionAttribute<Sensitive>> $attributes */
                $attributes = $property->getAttributes(Sensitive::class);
                if ([] !== $attributes) {
                    /** @var Sensitive $sensitive */
                    $sensitive = $attributes[0]->newInstance();
                    $sensitiveFields[$property->getName()] = $sensitive->mask;
                }
            }

            $constructor = $reflection->getConstructor();
            if (null !== $constructor) {
                foreach ($constructor->getParameters() as $param) {
                    $attributes = $param->getAttributes(\SensitiveParameter::class);
                    if ([] !== $attributes && $param->isPromoted() && !isset($sensitiveFields[$param->getName()])) {
                        $sensitiveFields[$param->getName()] = '**REDACTED**';
                    }
                }
            }
        } catch (\ReflectionException) {
            // Ignore
        }

        return $sensitiveFields;
    }
}
