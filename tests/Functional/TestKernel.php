<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Rcsofttech\AuditTrailBundle\AuditTrailBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;

class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /** @var array<string, mixed> */
    private array $auditConfig = [];

    public static bool $useThrowingTransport = false;

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this, \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_OPTIMIZE);
    }

    public function process(ContainerBuilder $container): void
    {
        if (self::$useThrowingTransport) {
            $container->register(ThrowingTransport::class, ThrowingTransport::class);
            if ($container->hasAlias(AuditTransportInterface::class)) {
                $container->removeAlias(AuditTransportInterface::class);
            }
            $container->setAlias(AuditTransportInterface::class, ThrowingTransport::class);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setAuditConfig(array $config): void
    {
        $this->auditConfig = $config;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/audit_trail_test/cache/' . md5(serialize($this->auditConfig));
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/audit_trail_test/logs';
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new SecurityBundle(),
            new AuditTrailBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader): void
    {
        $c->loadFromExtension('framework', [
            'test' => true,
            'secret' => 'test',
            'php_errors' => ['log' => false, 'throw' => false],
            'validation' => ['email_validation_mode' => 'html5'],
        ]);

        $c->loadFromExtension('security', [
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'security' => false,
                ],
            ],
        ]);

        $c->loadFromExtension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'url' => 'sqlite:///%kernel.cache_dir%/test.db',
            ],
            'orm' => [
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'auto_mapping' => true,
                'mappings' => [
                    'TestEntity' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'Rcsofttech\AuditTrailBundle\Tests\Functional\Entity',
                        'alias' => 'TestEntity',
                    ],
                ],
            ],
        ]);

        // Default config, can be overridden
        $auditConfig = array_merge([
            'enabled' => true,
            'transports' => [
                'doctrine' => true,
            ],
            'defer_transport_until_commit' => true, // Default
        ], $this->auditConfig);

        $c->loadFromExtension('audit_trail', $auditConfig);
    }
}
