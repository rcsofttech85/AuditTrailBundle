<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AuditIntegrityConfigurator
{
    /**
     * @param array{enabled: bool, secret: ?string, algorithm: string} $config
     */
    public function configure(ContainerBuilder $container, array $config): void
    {
        $container->getDefinition(AuditIntegrityService::class)
            ->setArgument('$enabled', $config['enabled'])
            ->setArgument('$secret', $config['secret'])
            ->setArgument('$algorithm', $config['algorithm']);
    }
}
