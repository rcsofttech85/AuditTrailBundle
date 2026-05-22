<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminFieldProvider as BridgeAuditLogAdminFieldProvider;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.1',
    'The "%s" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "%s" instead.',
    AuditLogAdminFieldProvider::class,
    BridgeAuditLogAdminFieldProvider::class,
);

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.1, use
 *             Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminFieldProvider instead.
 */
class AuditLogAdminFieldProvider extends BridgeAuditLogAdminFieldProvider
{
}
