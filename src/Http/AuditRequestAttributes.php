<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Http;

final class AuditRequestAttributes
{
    public const string ACCESS_INTENT = '_audit_access_intent';

    private function __construct()
    {
    }
}
