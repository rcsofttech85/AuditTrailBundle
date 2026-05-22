<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Tests\Support\InteractsWithUserDeprecations;

final class LegacyAuditLogAdminRequestMapperTest extends TestCase
{
    use InteractsWithUserDeprecations;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyMapperTriggersDeprecation(): void
    {
        $class = 'Rcsofttech\\AuditTrailBundle\\Service\\AuditLogAdminRequestMapper';

        $mapper = $this->expectSingleUserDeprecation(
            'Since rcsofttech/audit-trail-bundle 4.1: The "Rcsofttech\AuditTrailBundle\Service\AuditLogAdminRequestMapper" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminRequestMapper" instead.',
            static fn () => new $class(),
        );

        self::assertSame($class, $mapper::class);
    }
}
