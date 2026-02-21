<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;

interface AuditMetadataManagerInterface
{
    public function getAuditableAttribute(string $class): ?Auditable;

    public function getAuditAccessAttribute(string $class): ?AuditAccess;

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(string $class): array;

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string>
     */
    public function getIgnoredProperties(object $entity, array $additionalIgnored = []): array;

    public function isEntityIgnored(string $class): bool;
}
