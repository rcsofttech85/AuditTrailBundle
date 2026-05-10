<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditIntegrityConfigurator;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AuditIntegrityConfiguratorTest extends TestCase
{
    public function testConfigureAppliesIntegrityArgumentsToServiceDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AuditIntegrityService::class, new Definition(AuditIntegrityService::class));

        new AuditIntegrityConfigurator()->configure($container, [
            'enabled' => true,
            'secret' => 'test-secret',
            'algorithm' => 'sha512',
        ]);

        $definition = $container->getDefinition(AuditIntegrityService::class);
        self::assertTrue($definition->getArgument('$enabled'));
        self::assertSame('test-secret', $definition->getArgument('$secret'));
        self::assertSame('sha512', $definition->getArgument('$algorithm'));
    }
}
