<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;
use SensitiveParameter;

class SensitiveEntity
{
    #[Sensitive(mask: '***')]
    public string $secret = 'val';

    public string $public = 'val';

    public function __construct(
        #[SensitiveParameter]
        public string $password = 'pass',
    ) {
    }
}
