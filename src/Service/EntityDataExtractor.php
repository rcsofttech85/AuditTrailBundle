<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityDataExtractorInterface;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Throwable;

use function array_key_exists;
use function in_array;

final readonly class EntityDataExtractor implements EntityDataExtractorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValueSerializerInterface $serializer,
        private MetadataCacheInterface $metadataCache,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string> $ignored
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function extract(object $entity, array $ignored = [], ?EntityManagerInterface $entityManager = null): array
    {
        $class = $entity::class;
        try {
            $entityManager ??= $this->entityManager;
            $meta = $entityManager->getClassMetadata($class);
            $data = [];

            $this->processFields($meta, $entity, $ignored, $data);
            $this->processAssociations($meta, $entity, $ignored, $data, $entityManager);
            $this->applySensitiveMasking($class, $data);

            return $data;
        } catch (Throwable $e) {
            $this->logger?->error('Failed to extract entity data', [
                'exception' => $e->getMessage(),
                'entity' => $class,
            ]);

            return [
                '_extraction_failed' => true,
                '_error' => 'entity_data_extraction_failed',
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

            $resolved = $this->tryGetFieldValue($meta, $entity, $field);
            if ($resolved['success']) {
                $data[$field] = $this->serializer->serialize($resolved['value']);
            }
        }
    }

    /**
     * @param ClassMetadata<object> $meta
     * @param array<string>         $ignored
     * @param array<string, mixed>  $data
     */
    private function processAssociations(
        ClassMetadata $meta,
        object $entity,
        array $ignored,
        array &$data,
        EntityManagerInterface $entityManager,
    ): void {
        $uow = $entityManager->getUnitOfWork();

        foreach ($meta->getAssociationNames() as $assoc) {
            if (in_array($assoc, $ignored, true)) {
                continue;
            }

            $resolved = $this->tryGetFieldValue($meta, $entity, $assoc);
            if (!$resolved['success']) {
                continue;
            }

            $value = $resolved['value'];

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
     *
     * @return array{success: bool, value: mixed}
     */
    private function tryGetFieldValue(ClassMetadata $meta, object $entity, string $field): array
    {
        try {
            return [
                'success' => true,
                'value' => $meta->getFieldValue($entity, $field),
            ];
        } catch (Throwable) {
            return [
                'success' => false,
                'value' => null,
            ];
        }
    }
}
