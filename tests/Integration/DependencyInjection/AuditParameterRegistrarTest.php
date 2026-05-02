<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditParameterRegistrar;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AuditParameterRegistrarTest extends TestCase
{
    public function testRegisterStoresAllProvidedParameters(): void
    {
        $container = new ContainerBuilder();

        new AuditParameterRegistrar()->register($container, [
            'audit_trail.enabled' => true,
            'audit_trail.timezone' => 'UTC',
            'audit_trail.extra' => ['nested' => 'value'],
        ]);

        self::assertTrue($container->getParameter('audit_trail.enabled'));
        self::assertSame('UTC', $container->getParameter('audit_trail.timezone'));
        self::assertSame(['nested' => 'value'], $container->getParameter('audit_trail.extra'));
    }
}
