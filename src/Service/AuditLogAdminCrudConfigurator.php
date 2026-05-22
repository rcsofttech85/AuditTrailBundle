<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminCrudConfigurator as BridgeAuditLogAdminCrudConfigurator;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.1',
    'The "%s" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "%s" instead.',
    AuditLogAdminCrudConfigurator::class,
    BridgeAuditLogAdminCrudConfigurator::class,
);

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.1, use
 *             Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminCrudConfigurator instead.
 */
readonly class AuditLogAdminCrudConfigurator extends BridgeAuditLogAdminCrudConfigurator
{
    public function __construct(
        AuditLogAdminFieldProvider $fieldProvider,
        AuditLogAdminLocator $locator,
        string $adminPermission,
    ) {
        parent::__construct($fieldProvider, $locator, $adminPermission);
    }
}
