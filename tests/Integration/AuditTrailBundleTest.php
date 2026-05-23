<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\AuditTrailBundle;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function dirname;

final class AuditTrailBundleTest extends TestCase
{
    public function testBundleUsesAbstractBundleExtensionToLoadDefaultConfiguration(): void
    {
        $bundle = new AuditTrailBundle();

        self::assertContains(AbstractBundle::class, class_parents($bundle));

        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);
        self::assertSame('audit_trail', $extension->getAlias());

        $container = $this->createContainer();
        $extension->load([], $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.database'));
        self::assertTrue($container->hasAlias('Rcsofttech\\AuditTrailBundle\\Contract\\AuditTransportInterface'));
        self::assertSame('ROLE_ADMIN', $container->getParameter('audit_trail.easyadmin.permission'));
        self::assertSame(50000, $container->getParameter('audit_trail.easyadmin.export_limit'));
    }

    public function testBundlePrependsFrameworkUidAndDoctrineMappings(): void
    {
        $extension = new AuditTrailBundle()->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        $container = $this->createContainer();
        $container->registerExtension(new FrameworkExtension());
        $container->registerExtension(new DoctrineExtension());

        $extension->prepend($container);

        self::assertSame([
            ['uid' => []],
        ], $container->getExtensionConfig('framework'));

        $doctrineConfig = $container->getExtensionConfig('doctrine');
        self::assertCount(1, $doctrineConfig);
        self::assertSame([
            'is_bundle' => false,
            'type' => 'attribute',
            'prefix' => 'Rcsofttech\AuditTrailBundle\Entity',
            'alias' => 'AuditTrail',
        ], array_diff_key(
            $doctrineConfig[0]['orm']['mappings']['RcsofttechAuditTrailBundle'],
            ['dir' => true],
        ));
        self::assertStringEndsWith('/src/Entity', $doctrineConfig[0]['orm']['mappings']['RcsofttechAuditTrailBundle']['dir']);
    }

    public function testBundleRejectsNonStringIntegritySecretValues(): void
    {
        $extension = new AuditTrailBundle()->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        $container = $this->createContainer();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid type for path "audit_trail.integrity.secret"');

        $extension->load([
            [
                'integrity' => [
                    'enabled' => true,
                    'secret' => 123,
                ],
            ],
        ], $container);
    }

    public function testVersionUsesComposerInstalledVersionMetadata(): void
    {
        self::assertSame(
            InstalledVersions::getPrettyVersion('rcsofttech/audit-trail-bundle'),
            AuditTrailBundle::version(),
        );
    }

    private function createContainer(): ContainerBuilder
    {
        $buildDir = sys_get_temp_dir().'/audittrailbundle-build';
        $cacheDir = sys_get_temp_dir().'/audittrailbundle-cache';
        $logsDir = sys_get_temp_dir().'/audittrailbundle-logs';
        $projectDir = dirname(__DIR__, 2);

        $container = new ContainerBuilder(new ParameterBag([
            'kernel.project_dir' => $projectDir,
            'kernel.environment' => 'test',
            'kernel.debug' => true,
            'kernel.build_dir' => $buildDir,
            'kernel.cache_dir' => $cacheDir,
            'kernel.logs_dir' => $logsDir,
            'kernel.bundles' => [],
            'kernel.bundles_metadata' => [],
            'kernel.charset' => 'UTF-8',
            'kernel.container_class' => 'AuditTrailBundleTestContainer',
        ]));

        return $container;
    }
}
