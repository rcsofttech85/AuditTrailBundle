<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle;

use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTrailExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class AuditTrailBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    protected function createContainerExtension(): ExtensionInterface
    {
        return new AuditTrailExtension();
    }
}
