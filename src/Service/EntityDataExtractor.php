<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Throwable;

use function array_key_exists;
use function in_array;

class EntityDataExtractor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private ValueSerializerInterface $serializer,
        private readonly MetadataCache $metadataCache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string> $ignored
     *
     * @return array<string, mixed>
     */
    public function extract(object $entity, array $ignored = []): array
    {
        $class = $entity::class;
        try {
            $meta = $this->entityManager->getClassMetadata($class);
            $data = [];

            $this->processFields($meta, $entity, $ignored, $data);
            $this->processAssociations($meta, $entity, $ignored, $data);
            $this->applySensitiveMasking($class, $data);

            return $data;
        } catch (Throwable $e) {
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
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $value = $this->getFieldValueSafely($meta, $entity, $field);
            if ($value !== null) {
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
        $uow = $this->entityManager->getUnitOfWork();

        foreach ($meta->getAssociationNames() as $assoc) {
            if (in_array($assoc, $ignored, true)) {
                continue;
            }

            $value = $this->getFieldValueSafely($meta, $entity, $assoc);

            // Optimization: If it's an uninitialized proxy, extract only the ID to prevent N+1 query
            if ($value instanceof \Doctrine\Persistence\Proxy && !$value->__isInitialized()) {
                $identifier = $uow->getEntityIdentifier($value);
                $data[$assoc] = $this->serializer->serialize($identifier);
                continue;
            }

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
            if (array_key_exists($field, $data)) {
                $data[$field] = $mask;
            }
        }
    }

    /**
     * @param ClassMetadata<object> $meta
     */
    private function getFieldValueSafely(ClassMetadata $meta, object $entity, string $field): mixed
    {
        try {
            return $meta->getFieldValue($entity, $field);
        } catch (Throwable) {
            return null;
        }
    }
}
