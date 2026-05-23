<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.2, use AuditTrailBundle::configure() instead.
 */
final class Configuration implements ConfigurationInterface
{
    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return new AuditTrailConfigurationDefinition()->createTreeBuilder();
    }
}
