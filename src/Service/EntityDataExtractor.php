<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

class EntityDataExtractor
{
    /**
     * @param array<string> $ignoredProperties
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValueSerializer $serializer,
        private readonly MetadataCache $metadataCache,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $ignoredProperties = [],
    ) {
    }

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string, mixed>
     */
    public function extract(object $entity, array $additionalIgnored = []): array
    {
        $class = $entity::class;
        try {
            $meta = $this->entityManager->getClassMetadata($class);
            $ignored = $this->buildIgnoredPropertyList($entity, $additionalIgnored);
            $data = [];

            $this->processFields($meta, $entity, $ignored, $data);
            $this->processAssociations($meta, $entity, $ignored, $data);
            $this->applySensitiveMasking($class, $data);

            return $data;
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to extract entity data', [
                'exception' => $e->getMessage(),
                'entity' => $class,
            ]);

            return [
                '_extraction_failed' => true,
                '_error' => $e->getMessage(),
                '_entity_class' => $class,
            ];
        }
    }

    /**
     * @param ClassMetadata<object> $meta
     * @param array<string>         $ignored
     * @param array<string, mixed>  $data
     */
    private function processFields(ClassMetadata $meta, object $entity, array $ignored, array &$data): void
    {
        foreach ($meta->getFieldNames() as $field) {
            if (\in_array($field, $ignored, true)) {
                continue;
            }

            $value = $this->getFieldValueSafely($meta, $entity, $field);
            if (null !== $value) {
                $data[$field] = $this->serializer->serialize($value);
            }
        }
    }

    /**
     * @param ClassMetadata<object> $meta
     * @param array<string>         $ignored
     * @param array<string, mixed>  $data
     */
    private function processAssociations(ClassMetadata $meta, object $entity, array $ignored, array &$data): void
    {
        foreach ($meta->getAssociationNames() as $assoc) {
            if (\in_array($assoc, $ignored, true)) {
                continue;
            }

            $value = $this->getFieldValueSafely($meta, $entity, $assoc);
            $data[$assoc] = $this->serializer->serializeAssociation($value);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applySensitiveMasking(string $class, array &$data): void
    {
        $sensitiveFields = $this->metadataCache->getSensitiveFields($class);
        foreach ($sensitiveFields as $field => $mask) {
            if (\array_key_exists($field, $data)) {
                $data[$field] = $mask;
            }
        }
    }

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string>
     */
    private function buildIgnoredPropertyList(object $entity, array $additionalIgnored): array
    {
        $ignored = [...$this->ignoredProperties, ...$additionalIgnored];

        $auditable = $this->metadataCache->getAuditableAttribute($entity::class);
        if (null !== $auditable) {
            $ignored = [...$ignored, ...$auditable->ignoredProperties];
        }

        return array_unique($ignored);
    }

    /**
     * @param ClassMetadata<object> $meta
     */
    private function getFieldValueSafely(ClassMetadata $meta, object $entity, string $field): mixed
    {
        try {
            return $meta->getFieldValue($entity, $field);
        } catch (\Throwable) {
            return null;
        }
    }
}
