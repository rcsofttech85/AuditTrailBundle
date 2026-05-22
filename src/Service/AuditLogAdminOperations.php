<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminOperations as BridgeAuditLogAdminOperations;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.1',
    'The "%s" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "%s" instead.',
    AuditLogAdminOperations::class,
    BridgeAuditLogAdminOperations::class,
);

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.1, use
 *             Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminOperations instead.
 */
readonly class AuditLogAdminOperations extends BridgeAuditLogAdminOperations
{
    public function __construct(
        AuditReverterInterface $reverter,
        AuditLogRepositoryInterface $repository,
        AuditExporterInterface $exporter,
        RevertPreviewFormatter $formatter,
        TransactionDrilldownService $drilldownService,
        AuditLogAdminRequestMapper $requestMapper,
        int $adminExportLimit,
    ) {
        parent::__construct(
            $reverter,
            $repository,
            $exporter,
            $formatter,
            $drilldownService,
            $requestMapper,
            $adminExportLimit,
        );
    }
}
