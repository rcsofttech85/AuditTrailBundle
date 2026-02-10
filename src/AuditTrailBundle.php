<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

use function dirname;

final class AuditTrailBundle extends Bundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
