<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use DAMA\DoctrineTestBundle\DAMADoctrineTestBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Rcsofttech\AuditTrailBundle\AuditTrailBundle;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /** @var array<string, mixed> */
    private array $auditConfig = [];

    /** @var array<string, mixed> */
    private array $doctrineConfig = [];

    /** @var array<string, mixed> */
    private array $frameworkConfig = [];

    public static bool $useThrowingTransport = false;

    /** @var list<string>|null */
    public static ?array $throwingTransportSupportedPhases = null;

    /** @var list<string> */
    public static array $publicServiceIds = [];

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this, PassConfig::TYPE_OPTIMIZE);
    }

    public function process(ContainerBuilder $container): void
    {
        if (self::$useThrowingTransport) {
            $container->register(ThrowingTransport::class, ThrowingTransport::class);
            if ($container->hasAlias(AuditTransportInterface::class)) {
                $container->removeAlias(AuditTransportInterface::class);
            }
            $container->setAlias(AuditTransportInterface::class, ThrowingTransport::class);

            // Silence logger during expected failures
            $container->register('logger', NullLogger::class);
        }

        foreach (self::$publicServiceIds as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->getDefinition($serviceId)->setPublic(true);
            }

            if ($container->hasAlias($serviceId)) {
                $container->getAlias($serviceId)->setPublic(true);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setAuditConfig(array $config): void
    {
        $this->auditConfig = $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setDoctrineConfig(array $config): void
    {
        $this->doctrineConfig = $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setFrameworkConfig(array $config): void
    {
        $this->frameworkConfig = $config;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/audit_trail_test/cache/'.
            md5(serialize([
                realpath(dirname(__DIR__, 2)),
                $this->auditConfig,
                $this->doctrineConfig,
                $this->frameworkConfig,
                self::$useThrowingTransport,
                self::$throwingTransportSupportedPhases,
                self::$publicServiceIds,
            ]));
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/audit_trail_test/logs';
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new SecurityBundle(),
            new AuditTrailBundle(),
            new DAMADoctrineTestBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader): void
    {
        $c->setParameter('env(AUDIT_INTEGRITY_SECRET)', 'test-integrity-secret-for-suite-123');
        $c->setParameter('env(AUDIT_INTEGRITY_PRESSURE_SECRET)', 'pressure-secret-for-suite-verify-123');

        $defaultFrameworkConfig = [
            'test' => true,
            'secret' => 'test-integrity-secret-for-kernel-123',
            'php_errors' => ['log' => false, 'throw' => false],
            'validation' => ['email_validation_mode' => 'html5'],
            'cache' => [
                'pools' => [
                    'audit_test.cache' => ['adapter' => 'cache.adapter.filesystem'],
                ],
            ],
        ];

        /** @var array<string, mixed> $frameworkConfig */
        $frameworkConfig = array_replace_recursive($defaultFrameworkConfig, $this->frameworkConfig);

        $c->loadFromExtension('framework', $frameworkConfig);

        $c->loadFromExtension('security', [
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'security' => false,
                ],
            ],
        ]);

        $defaultDoctrineConfig = [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'url' => 'sqlite:///:memory:',
            ],
            'orm' => [
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'mappings' => [
                    'TestEntity' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Rcsofttech\AuditTrailBundle\Tests\Functional\Entity',
                        'alias' => 'TestEntity',
                    ],
                ],
            ],
        ];

        /** @var array<string, mixed> $doctrineConfig */
        $doctrineConfig = array_replace_recursive($defaultDoctrineConfig, $this->doctrineConfig);

        $c->loadFromExtension('doctrine', $doctrineConfig);

        // Default config, can be overridden
        $auditConfig = array_merge([
            'enabled' => true,
            'transports' => [
                'database' => ['enabled' => true],
            ],
            'cache_pool' => 'audit_test.cache',
            'defer_transport_until_commit' => true, // Default
        ], $this->auditConfig);

        $c->loadFromExtension('audit_trail', $auditConfig);
    }
}
