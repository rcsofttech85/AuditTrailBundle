<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\DataCollector\AuditDataCollector;
use Rcsofttech\AuditTrailBundle\DataCollector\TraceableAuditCollector;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditProfilerRegistrar;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AuditProfilerRegistrarTest extends TestCase
{
    public function testRegisterSkipsWhenProfilerBundleIsMissing(): void
    {
        $container = new ContainerBuilder();

        new AuditProfilerRegistrar()->register($container, []);

        self::assertFalse($container->hasDefinition(TraceableAuditCollector::class));
        self::assertFalse($container->hasDefinition(AuditDataCollector::class));
    }

    public function testRegisterAddsProfilerCollectorsWhenBundleIsPresent(): void
    {
        if (!class_exists(AbstractDataCollector::class)) {
            self::markTestSkipped('FrameworkBundle profiler classes are not installed.');
        }

        $container = new ContainerBuilder();

        new AuditProfilerRegistrar()->register($container, ['WebProfilerBundle' => true]);

        self::assertTrue($container->hasDefinition(TraceableAuditCollector::class));
        self::assertTrue($container->hasDefinition(AuditDataCollector::class));

        $traceableDefinition = $container->getDefinition(TraceableAuditCollector::class);
        self::assertTrue($traceableDefinition->isAutowired());
        self::assertTrue($traceableDefinition->isAutoconfigured());
        self::assertSame([['method' => 'reset']], $traceableDefinition->getTag('kernel.reset'));

        $collectorDefinition = $container->getDefinition(AuditDataCollector::class);
        self::assertSame([[
            'id' => 'audit_trail',
            'template' => '@AuditTrail/Collector/audit.html.twig',
            'priority' => 260,
        ]], $collectorDefinition->getTag('data_collector'));
    }
}
