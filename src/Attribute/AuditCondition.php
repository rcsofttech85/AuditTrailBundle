<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AuditCondition
{
    public function __construct(
        public string $expression,
    ) {
    }
}
