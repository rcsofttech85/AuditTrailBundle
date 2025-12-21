<?php

namespace Rcsofttech\AuditTrailBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class AuditTrailBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}