<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminLocator as BridgeAuditLogAdminLocator;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.1',
    'The "%s" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "%s" instead.',
    AuditLogAdminLocator::class,
    BridgeAuditLogAdminLocator::class,
);

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.1, use
 *             Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminLocator instead.
 */
readonly class AuditLogAdminLocator extends BridgeAuditLogAdminLocator
{
}
