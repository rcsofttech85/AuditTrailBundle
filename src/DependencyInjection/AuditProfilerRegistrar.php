<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Rcsofttech\AuditTrailBundle\DataCollector\AuditDataCollector;
use Rcsofttech\AuditTrailBundle\DataCollector\TraceableAuditCollector;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;

final class AuditProfilerRegistrar
{
    /**
     * @param array<string, mixed> $bundles
     */
    public function register(ContainerBuilder $container, array $bundles): void
    {
        if (!class_exists(AbstractDataCollector::class) || !isset($bundles['WebProfilerBundle'])) {
            return;
        }

        $container->register(TraceableAuditCollector::class, TraceableAuditCollector::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);

        $container->register(AuditDataCollector::class, AuditDataCollector::class)
            ->setAutowired(true)
            ->addTag('data_collector', [
                'id' => 'audit_trail',
                'template' => '@AuditTrail/Collector/audit.html.twig',
                'priority' => 260,
            ]);
    }
}
