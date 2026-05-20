<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests;

use function is_string;

final class TestDatabaseUrlResolver
{
    public static function resolve(string $environmentVariable = 'AUDIT_TRAIL_TEST_DATABASE_URL'): ?string
    {
        $databaseUrl = getenv($environmentVariable);
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            return null;
        }

        return $databaseUrl;
    }
}
