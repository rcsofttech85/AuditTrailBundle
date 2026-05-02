<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AuditParameterRegistrar
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function register(ContainerBuilder $container, array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
    }
}
