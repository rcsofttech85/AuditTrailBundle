<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[Auditable(enabled: true)]
class ParentAuditable
{
}
