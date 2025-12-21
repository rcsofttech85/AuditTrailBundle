<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Auditable
{
    public function __construct(
        public bool $enabled = true,
        public array $ignoredProperties = [],

    ) {
    }
}
