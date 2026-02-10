<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Attribute;

use Attribute;

/**
 * Marks an entity property as sensitive.
 *
 * When an entity field is marked with this attribute, its value will be
 * replaced with a mask in audit logs to prevent sensitive data exposure.
 *
 * Usage:
 *     #[Sensitive]
 *     private string $password;
 *
 *     #[Sensitive(mask: '****')]
 *     private string $ssn;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Sensitive
{
    public function __construct(
        public string $mask = '**REDACTED**',
    ) {
    }
}
