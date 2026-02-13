<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AuditAccess
{
    public function __construct(
        public string $level = 'info',
        public ?string $message = null,
        public int $cooldown = 0,
    ) {
    }
}
