<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;

use function in_array;

final class AuditMetadataManager implements AuditMetadataManagerInterface
{
    /**
     * @param array<string> $ignoredEntities
     * @param array<string> $ignoredProperties
     */
    public function __construct(
        private readonly MetadataCache $metadataCache,
        private readonly array $ignoredEntities = [],
        private readonly array $ignoredProperties = [],
    ) {
    }

    public function getAuditableAttribute(string $class): ?Auditable
    {
        return $this->metadataCache->getAuditableAttribute($class);
    }

    public function getAuditAccessAttribute(string $class): ?AuditAccess
    {
        return $this->metadataCache->getAuditAccessAttribute($class);
    }

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(string $class): array
    {
        return $this->metadataCache->getSensitiveFields($class);
    }

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string>
     */
    public function getIgnoredProperties(object $entity, array $additionalIgnored = []): array
    {
        $ignored = [...$this->ignoredProperties, ...$additionalIgnored];

        $auditable = $this->getAuditableAttribute($entity::class);
        if ($auditable !== null) {
            $ignored = [...$ignored, ...$auditable->ignoredProperties];
        }

        return array_unique($ignored);
    }

    public function isEntityIgnored(string $class): bool
    {
        if (in_array($class, $this->ignoredEntities, true)) {
            return true;
        }

        $auditable = $this->getAuditableAttribute($class);

        return $auditable === null || !$auditable->enabled;
    }
}
