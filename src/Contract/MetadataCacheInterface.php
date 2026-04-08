<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;

interface MetadataCacheInterface
{
    public function getAuditableAttribute(string|object $class): ?Auditable;

    public function getAuditAccessAttribute(string|object $class): ?AuditAccess;

    public function getAuditCondition(string|object $class): ?AuditCondition;

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(string|object $class): array;
}
