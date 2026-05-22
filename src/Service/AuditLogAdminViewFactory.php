<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminViewFactory as BridgeAuditLogAdminViewFactory;
use Twig\Environment;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.1',
    'The "%s" class is deprecated since rcsofttech/audit-trail-bundle 4.1; use "%s" instead.',
    AuditLogAdminViewFactory::class,
    BridgeAuditLogAdminViewFactory::class,
);

/**
 * @deprecated since rcsofttech/audit-trail-bundle 4.1, use
 *             Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminViewFactory instead.
 */
readonly class AuditLogAdminViewFactory extends BridgeAuditLogAdminViewFactory
{
    public function __construct(
        AuditLogAdminLocator $locator,
        AuditLogAdminOperations $operations,
        AuditLogExportResponseFactory $exportResponseFactory,
        AdminUrlGenerator $adminUrlGenerator,
        Environment $twig,
    ) {
        parent::__construct(
            $locator,
            $operations,
            $exportResponseFactory,
            $adminUrlGenerator,
            $twig,
        );
    }
}
