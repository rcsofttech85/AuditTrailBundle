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
    private const string ENTITY_ID_SEPARATOR = '-';
    private const string PENDING_ID = 'pending';

    /** @var array<string, Auditable|null> */
    private array $auditableCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserResolverInterface $userResolver,
        private readonly ClockInterface $clock,
        private readonly array $ignoredProperties = [],
        private readonly array $ignoredEntities = [],
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Check if the entity should be audited
     */
    public function shouldAudit(object $entity): bool
    {
        $class = $entity::class;

        // Skip ignored entities
        if (\in_array($class, $this->ignoredEntities, true)) {
            return false;
        }

        $auditable = $this->getAuditableAttribute($entity);

        return $auditable !== null && $auditable->enabled;
    }

    /**
     * Extract entity data for auditing
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
                if ($value !== null) {
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
            return [];
        }
    }

    /**
     * Create audit log entry
     */
    public function createAuditLog(
        object $entity,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        $auditLog = new AuditLog();
        $auditLog->setEntityClass($entity::class);
        $auditLog->setEntityId($this->getEntityId($entity));
        $auditLog->setAction($action);
        $auditLog->setOldValues($oldValues);
        $auditLog->setNewValues($newValues);

        // Determine changed fields for updates
        if ($action === AuditLog::ACTION_UPDATE && $oldValues !== null && $newValues !== null) {
            $changedFields = $this->detectChangedFields($oldValues, $newValues);
            $auditLog->setChangedFields($changedFields);
        }

        // Set user context
        $this->enrichWithUserContext($auditLog);

        // Set creation time from clock
        $auditLog->setCreatedAt($this->clock->now());

        return $auditLog;
    }

    /**
     * Get cached Auditable attribute for entity
     */
    private function getAuditableAttribute(object $entity): ?Auditable
    {
        $class = $entity::class;

        if (\array_key_exists($class, $this->auditableCache)) {
            return $this->auditableCache[$class];
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
     * Build comprehensive list of ignored properties
     */
    private function buildIgnoredPropertyList(object $entity, array $additionalIgnored): array
    {
        $ignored = [...$this->ignoredProperties, ...$additionalIgnored];

        $auditable = $this->getAuditableAttribute($entity);
        if ($auditable !== null) {
            $ignored = [...$ignored, ...$auditable->ignoredProperties];
        }

        return array_unique($ignored);
    }

    /**
     * Safely get field value with error handling
     */
    private function getFieldValueSafely(ClassMetadata $meta, object $entity, string $field): mixed
    {
        try {
            return $meta->getFieldValue($entity, $field);
        } catch (\Throwable $e) {
            $this->logError('Failed to get field value', $e, [
                'entity' => $entity::class,
                'field' => $field
            ]);
            return null;
        }
    }

    /**
     * Serialize association values
     */
    private function serializeAssociation(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle collections (OneToMany, ManyToMany)
        if ($value instanceof Collection) {
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
     * Extract identifier from entity object
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
     * Detect changed fields between old and new values
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
     * Compare values with type-aware logic
     */
    private function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
    {
        // Handle null comparisons
        if ($oldValue === null || $newValue === null) {
            return $oldValue !== $newValue;
        }

        // Numeric comparison with type coercion
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return (float) $oldValue !== (float) $newValue;
        }

        // Array comparison
        if (\is_array($oldValue) && is_array($newValue)) {
            return $oldValue !== $newValue;
        }

        // Standard comparison
        return $oldValue !== $newValue;
    }

    /**
     * Enrich audit log with user context
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
     * Get entity identifier as string (supports composite keys)
     */
    private function getEntityId(object $entity): string
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);

            if (empty($ids)) {
                return self::PENDING_ID;
            }

            // Filter out null values and convert to strings
            $idValues = array_filter(
                array_map('strval', $ids),
                fn($id) => $id !== ''
            );

            return !empty($idValues)
                ? implode(self::ENTITY_ID_SEPARATOR, $idValues)
                : self::PENDING_ID;
        } catch (\Throwable $e) {
            $this->logError('Failed to get entity ID', $e, ['entity' => $entity::class]);
            return self::PENDING_ID;
        }
    }

    /**
     * Serialize values for logging with depth protection
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
            \is_object($value) => $this->serializeObject($value),

            // Array handling with recursion protection
            \is_array($value) => array_map(
                fn($v) => $this->serializeValue($v, $depth + 1),
                $value
            ),

            // Resource handling
            \is_resource($value) => sprintf('[resource: %s]', get_resource_type($value)),

            // Default: return as-is
            default => $value,
        };
    }

    /**
     * Serialize object values
     */
    private function serializeObject(object $value): mixed
    {
        if (method_exists($value, 'getId')) {
            return $value->getId();
        }

        if (method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value::class;
    }

    /**
     * Log errors if logger is available
     */
    private function logError(string $message, \Throwable $exception, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                ...$context
            ]);
        }
    }
}
