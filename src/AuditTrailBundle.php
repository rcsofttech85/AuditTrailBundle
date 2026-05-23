<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Override;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTrailConfigurationDefinition;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTrailContainerConfigurator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function class_exists;
use function is_string;

final class AuditTrailBundle extends AbstractBundle
{
    private const string PACKAGE_NAME = 'rcsofttech/audit-trail-bundle';

    /**
     * @deprecated since rcsofttech/audit-trail-bundle 4.2, use self::version() instead.
     */
    public const string VERSION = '4.0.0';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        new AuditTrailConfigurationDefinition()->configure($definition);
    }

    #[Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        new AuditTrailContainerConfigurator()->prepend($builder, __DIR__.'/Entity');
    }

    /**
     * @param array<mixed> $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        new AuditTrailContainerConfigurator()->load($config, $builder, static function (string $file) use ($container): void {
            $container->import(__DIR__.'/Resources/config/'.$file);
        });
    }

    #[Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    public static function version(): string
    {
        static $version = null;

        if (is_string($version)) {
            return $version;
        }

        if (!class_exists(InstalledVersions::class)) {
            return $version = 'unknown';
        }

        try {
            return $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME)
                ?? InstalledVersions::getVersion(self::PACKAGE_NAME)
                ?? 'unknown';
        } catch (OutOfBoundsException) {
            return $version = 'unknown';
        }
    }
}
