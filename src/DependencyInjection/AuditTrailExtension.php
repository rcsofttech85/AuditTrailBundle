<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.2, use AuditTrailBundle::loadExtension() and AuditTrailBundle::prependExtension() instead.
 */
final class AuditTrailExtension extends Extension implements PrependExtensionInterface
{
    public function __construct(
        private readonly AuditParameterRegistrar $parameterRegistrar = new AuditParameterRegistrar(),
        private readonly AuditIntegrityConfigurator $integrityConfigurator = new AuditIntegrityConfigurator(),
        private readonly AuditProfilerRegistrar $profilerRegistrar = new AuditProfilerRegistrar(),
        private readonly AuditTransportRegistrar $transportRegistrar = new AuditTransportRegistrar(),
    ) {
    }

    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->containerConfigurator()->load($config, $container, static function (string $file) use ($loader): void {
            $loader->load($file);
        });
    }

    #[Override]
    public function prepend(ContainerBuilder $container): void
    {
        $this->containerConfigurator()->prepend($container, __DIR__.'/../Entity');
    }

    #[Override]
    public function getAlias(): string
    {
        return 'audit_trail';
    }

    private function containerConfigurator(): AuditTrailContainerConfigurator
    {
        return new AuditTrailContainerConfigurator(
            $this->parameterRegistrar,
            $this->integrityConfigurator,
            $this->profilerRegistrar,
            $this->transportRegistrar,
        );
    }
}
