<?php

namespace Rcsofttech\AuditTrailBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Auditable
{
    public function __construct(
        public bool $enabled = true,
        public array $ignoredProperties = [],
        
    ) {}
}
