<?php

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

class AuditService
{
    private const int MAX_SERIALIZATION_DEPTH = 5;
    private const int MAX_AUDITABLE_CACHE = 100;
    private const int MAX_COLLECTION_ITEMS = 100;
    private const string PENDING_ID = 'pending';

    /** @var array<string, Auditable|null> */
    private array $auditableCache = [];

    /**
     * @param array<string> $ignoredProperties
     * @param array<string> $ignoredEntities
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserResolverInterface $userResolver,
        private readonly ClockInterface $clock,
        private readonly array $ignoredProperties = [],
        private readonly array $ignoredEntities = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Check if the entity should be audited.
     */
    public function shouldAudit(object $entity): bool
    {
        $class = $entity::class;

        // Skip ignored entities
        if (\in_array($class, $this->ignoredEntities, true)) {
            return false;
        }

        $auditable = $this->getAuditableAttribute($entity);

        return null !== $auditable && $auditable->enabled;
    }

    /**
     * Extract entity data for auditing.
     *
     * @param array<string> $additionalIgnored
     *
     * @return array<string, mixed>
     */
    public function getEntityData(object $entity, array $additionalIgnored = []): array
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $ignored = $this->buildIgnoredPropertyList($entity, $additionalIgnored);
            $data = [];

            // Extract scalar fields
            foreach ($meta->getFieldNames() as $field) {
                if (\in_array($field, $ignored, true)) {
                    continue;
                }

                $value = $this->getFieldValueSafely($meta, $entity, $field);
                if (null !== $value) {
                    $data[$field] = $this->serializeValue($value);
                }
            }

            // Extract associations
            foreach ($meta->getAssociationNames() as $assoc) {
                if (\in_array($assoc, $ignored, true)) {
                    continue;
                }

                $value = $this->getFieldValueSafely($meta, $entity, $assoc);
                $data[$assoc] = $this->serializeAssociation($value);
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logError('Failed to extract entity data', $e, ['entity' => $entity::class]);

            return [
                '_extraction_failed' => true,
                '_error' => $e->getMessage(),
                '_entity_class' => $entity::class,
            ];
        }
    }

    /**
     * Create audit log entry.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function createAuditLog(
        object $entity,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        $auditLog = new AuditLog();
        $auditLog->setEntityClass($entity::class);

        $entityId = $this->getEntityId($entity);
        if (self::PENDING_ID === $entityId && AuditLog::ACTION_DELETE === $action && null !== $oldValues) {
            $entityId = $this->extractIdFromValues($entity, $oldValues) ?? self::PENDING_ID;
        }
        $auditLog->setEntityId($entityId);

        $auditLog->setAction($action);
        $auditLog->setOldValues($oldValues);
        $auditLog->setNewValues($newValues);

        // Determine changed fields for updates
        if (AuditLog::ACTION_UPDATE === $action && null !== $oldValues && null !== $newValues) {
            $changedFields = $this->detectChangedFields($oldValues, $newValues);
            $auditLog->setChangedFields($changedFields);
        }

        // Set user context
        $this->enrichWithUserContext($auditLog);

        $auditLog->setCreatedAt($this->clock->now());

        return $auditLog;
    }

    /**
     * Get cached Auditable attribute for entity.
     */
    private function getAuditableAttribute(object $entity): ?Auditable
    {
        $class = $entity::class;

        if (\array_key_exists($class, $this->auditableCache)) {
            return $this->auditableCache[$class];
        }

        // Evict oldest if at limit
        if (\count($this->auditableCache) >= self::MAX_AUDITABLE_CACHE) {
            array_shift($this->auditableCache);
        }

        try {
            $reflection = new \ReflectionClass($entity);
            $attributes = $reflection->getAttributes(Auditable::class);

            $this->auditableCache[$class] = !empty($attributes)
                ? $attributes[0]->newInstance()
                : null;
        } catch (\ReflectionException $e) {
            $this->logError('Failed to get Auditable attribute', $e, ['class' => $class]);
            $this->auditableCache[$class] = null;
        }

        return $this->auditableCache[$class];
    }

    /**
     * Build comprehensive list of ignored properties.
     *
     * @param array<string> $additionalIgnored
     *
     * @return array<int, string>
     */
    private function buildIgnoredPropertyList(object $entity, array $additionalIgnored): array
    {
        $ignored = [...$this->ignoredProperties, ...$additionalIgnored];

        $auditable = $this->getAuditableAttribute($entity);
        if (null !== $auditable) {
            $ignored = [...$ignored, ...$auditable->ignoredProperties];
        }

        return array_unique($ignored);
    }

    /**
     * Safely get field value with error handling.
     *
     * @param ClassMetadata<object> $meta
     */
    private function getFieldValueSafely(ClassMetadata $meta, object $entity, string $field): mixed
    {
        try {
            return $meta->getFieldValue($entity, $field);
        } catch (\Throwable $e) {
            $this->logError('Failed to get field value', $e, [
                'entity' => $entity::class,
                'field' => $field,
            ]);

            return null;
        }
    }

    /**
     * Serialize association values.
     */
    private function serializeAssociation(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        // Handle collections (OneToMany, ManyToMany)
        if ($value instanceof Collection) {
            $count = $value->count();

            if ($count > self::MAX_COLLECTION_ITEMS) {
                $this->logger?->warning('Collection exceeds max items for audit', [
                    'count' => $count,
                    'max' => self::MAX_COLLECTION_ITEMS,
                ]);

                return [
                    '_truncated' => true,
                    '_total_count' => $count,
                    '_sample' => array_map(
                        fn ($item) => $this->extractEntityIdentifier($item),
                        $value->slice(0, self::MAX_COLLECTION_ITEMS)
                    ),
                ];
            }

            return $value->map(function ($item) {
                return $this->extractEntityIdentifier($item);
            })->toArray();
        }

        // Handle single associations
        if (\is_object($value)) {
            return $this->extractEntityIdentifier($value);
        }

        return null;
    }

    /**
     * Extract identifier from entity object.
     */
    private function extractEntityIdentifier(object $entity): mixed
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        // Fallback to entity class name if no ID method
        return $entity::class;
    }

    /**
     * Detect changed fields between old and new values.
     *
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     *
     * @return array<int, string>
     */
    private function detectChangedFields(array $oldValues, array $newValues): array
    {
        $changed = [];

        foreach ($newValues as $field => $newValue) {
            if (!\array_key_exists($field, $oldValues)) {
                $changed[] = $field;
                continue;
            }

            $oldValue = $oldValues[$field];

            // Normalize values for comparison
            if ($this->valuesAreDifferent($oldValue, $newValue)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Compare values with type-aware logic.
     */
    private function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
    {
        // Handle null comparisons
        if (null === $oldValue || null === $newValue) {
            return $oldValue !== $newValue;
        }

        // Numeric comparison with type coercion
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            $old = (float) $oldValue;
            $new = (float) $newValue;

            // Use epsilon for float comparison
            return abs($old - $new) > 1e-9;
        }

        // Array comparison
        if (\is_array($oldValue) && is_array($newValue)) {
            return $oldValue !== $newValue;
        }

        // Standard comparison
        return $oldValue !== $newValue;
    }

    /**
     * Enrich audit log with user context.
     */
    private function enrichWithUserContext(AuditLog $auditLog): void
    {
        try {
            $auditLog->setUserId($this->userResolver->getUserId());
            $auditLog->setUsername($this->userResolver->getUsername());
            $auditLog->setIpAddress($this->userResolver->getIpAddress());
            $auditLog->setUserAgent($this->userResolver->getUserAgent());
        } catch (\Throwable $e) {
            $this->logError('Failed to set user context', $e);
        }
    }

    /**
     * Get entity identifier as string (supports composite keys).
     */
    public function getEntityId(object $entity): string
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);

            if (empty($ids)) {
                // Fallback: Try getId() method directly
                if (method_exists($entity, 'getId')) {
                    $id = $entity->getId();

                    return null !== $id ? (string) $id : self::PENDING_ID;
                }

                return self::PENDING_ID;
            }

            // Filter out null values and convert to strings
            $idValues = array_filter(
                array_map('strval', $ids),
                fn ($id) => '' !== $id
            );

            return !empty($idValues)
                ? json_encode(array_values($idValues), JSON_THROW_ON_ERROR)
                : self::PENDING_ID;
        } catch (\Throwable $e) {
            // Fallback: Try getId() method directly on exception
            if (method_exists($entity, 'getId')) {
                try {
                    $id = $entity->getId();

                    return null !== $id ? (string) $id : self::PENDING_ID;
                } catch (\Throwable) {
                    // Ignore fallback error
                }
            }

            $this->logError('Failed to get entity ID', $e, ['entity' => $entity::class]);

            return self::PENDING_ID;
        }
    }

    /**
     * Extract ID from values array using metadata.
     *
     * @param array<string, mixed> $values
     */
    private function extractIdFromValues(object $entity, array $values): ?string
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $idFields = $meta->getIdentifierFieldNames();
            $ids = [];

            foreach ($idFields as $idField) {
                if (!isset($values[$idField])) {
                    return null;
                }
                $ids[] = (string) $values[$idField];
            }

            return count($ids) > 1
                ? json_encode($ids, JSON_THROW_ON_ERROR)
                : ($ids[0] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Serialize values for logging with depth protection.
     */
    private function serializeValue(mixed $value, int $depth = 0): mixed
    {
        // Prevent infinite recursion
        if ($depth >= self::MAX_SERIALIZATION_DEPTH) {
            return '[max depth reached]';
        }

        return match (true) {
            // DateTime serialization with timezone
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),

            // Collection handling
            $value instanceof Collection => $value->map(function ($item) use ($depth) {
                return $this->serializeValue($item, $depth + 1);
            })->toArray(),

            // Object handling
            \is_object($value) => $this->serializeObject($value, $depth),

            // Array handling with recursion protection
            \is_array($value) => array_map(
                fn ($v) => $this->serializeValue($v, $depth + 1),
                $value
            ),

            // Resource handling
            \is_resource($value) => sprintf('[resource: %s]', get_resource_type($value)),

            // Default: return as-is
            default => $value,
        };
    }

    /**
     * Serialize object values.
     */
    private function serializeObject(object $value, int $depth = 0): mixed
    {
        if (method_exists($value, 'getId')) {
            return $value->getId();
        }

        if (method_exists($value, '__toString')) {
            try {
                return (string) $value;
            } catch (\Throwable $e) { // @phpstan-ignore catch.neverThrown
                return sprintf('[toString error: %s]', $value::class);
            }
        }

        return $value::class;
    }

    /**
     * Log errors if logger is available.
     *
     * @param array<string, mixed> $context
     */
    private function logError(string $message, \Throwable $exception, array $context = []): void
    {
        if (null !== $this->logger) {
            $this->logger->error($message, [
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'code' => $exception->getCode(),
                ...$context,
            ]);
        }
    }
}
