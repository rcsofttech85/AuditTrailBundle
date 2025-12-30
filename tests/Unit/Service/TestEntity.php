<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[Auditable(enabled: true)]
class TestEntity
{
    public function getId(): int
    {
        return 1;
    }
}
