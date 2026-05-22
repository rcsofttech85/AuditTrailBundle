<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogExportResponseFactory as BridgeAuditLogExportResponseFactory;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.1',
    'The "%s" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "%s" instead.',
    AuditLogExportResponseFactory::class,
    BridgeAuditLogExportResponseFactory::class,
);

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.1, use
 *             Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogExportResponseFactory instead.
 */
readonly class AuditLogExportResponseFactory extends BridgeAuditLogExportResponseFactory
{
    public function __construct(
        AuditLogAdminOperations $operations,
    ) {
        parent::__construct($operations);
    }
}
