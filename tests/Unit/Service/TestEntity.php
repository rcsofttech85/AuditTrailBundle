<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[Auditable(enabled: true)]
class TestEntity
{
    public function __construct(private int $id = 1)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}
