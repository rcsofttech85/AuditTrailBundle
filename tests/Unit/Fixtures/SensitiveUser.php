<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;

#[Auditable]
class SensitiveUser
{
    public function getId(): int
    {
        return 1;
    }

    #[Sensitive]
    public string $password = 'secret';

    public string $username = 'user';
}
