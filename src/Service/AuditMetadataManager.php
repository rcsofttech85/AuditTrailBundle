<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;

use function array_fill_keys;
use function array_unique;
use function implode;
use function sort;

use const SORT_STRING;

final class AuditMetadataManager implements AuditMetadataManagerInterface
{
    /** @var array<string, true> */
    private readonly array $ignoredEntityLookup;

    /** @var array<string, array<string>> */
    private array $ignoredPropertiesCache = [];

    /**
     * @param array<string> $ignoredEntities
     * @param array<string> $ignoredProperties
     */
    public function __construct(
        private readonly MetadataCacheInterface $metadataCache,
        private readonly array $ignoredEntities = [],
        private readonly array $ignoredProperties = [],
    ) {
        $this->ignoredEntityLookup = array_fill_keys($this->ignoredEntities, true);
    }

    #[Override]
    public function getAuditableAttribute(string $class): ?Auditable
    {
        return $this->metadataCache->getAuditableAttribute($class);
    }

    #[Override]
    public function getAuditAccessAttribute(string $class): ?AuditAccess
    {
        return $this->metadataCache->getAuditAccessAttribute($class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function getSensitiveFields(string $class): array
    {
        return $this->metadataCache->getSensitiveFields($class);
    }

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string>
     */
    #[Override]
    public function getIgnoredProperties(object $entity, array $additionalIgnored = []): array
    {
        $cacheKey = $this->buildIgnoredPropertiesCacheKey($entity::class, $additionalIgnored);

        return $this->ignoredPropertiesCache[$cacheKey] ??= $this->buildIgnoredProperties($entity::class, $additionalIgnored);
    }

    #[Override]
    public function isEntityIgnored(string $class): bool
    {
        if (isset($this->ignoredEntityLookup[$class])) {
            return true;
        }

        $auditable = $this->getAuditableAttribute($class);

        return $auditable === null || !$auditable->enabled;
    }

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string>
     */
    private function buildIgnoredProperties(string $class, array $additionalIgnored): array
    {
        sort($additionalIgnored, SORT_STRING);

        $ignored = [...$this->ignoredProperties, ...$additionalIgnored];
        $auditable = $this->getAuditableAttribute($class);
        if ($auditable !== null) {
            $ignored = [...$ignored, ...$auditable->ignoredProperties];
        }

        return array_values(array_unique($ignored));
    }

    /**
     * @param array<string> $additionalIgnored
     */
    private function buildIgnoredPropertiesCacheKey(string $class, array $additionalIgnored): string
    {
        if ($additionalIgnored === []) {
            return $class;
        }

        sort($additionalIgnored, SORT_STRING);

        return $class.':'.implode(',', $additionalIgnored);
    }
}
