<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Override;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminCrudConfigurator;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminLocator;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminOperations;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminViewFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * @extends AbstractCrudController<AuditLog>
 */
final class AuditLogCrudController extends AbstractCrudController
{
    private const int DRILLDOWN_LIMIT = 15;

    public function __construct(
        private readonly AuditLogAdminCrudConfigurator $crudConfigurator,
        private readonly AuditLogAdminLocator $locator,
        private readonly AuditLogAdminViewFactory $viewFactory,
        private readonly AuditLogAdminOperations $operations,
        private readonly string $adminPermission,
    ) {
    }

    #[Override]
    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $this->crudConfigurator->configureCrud($crud);
    }

    #[Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        return $this->crudConfigurator->configureResponseParameters($responseParameters);
    }

    #[Override]
    public function configureActions(Actions $actions): Actions
    {
        return $this->crudConfigurator->configureActions($actions);
    }

    #[Override]
    public function configureAssets(Assets $assets): Assets
    {
        return $this->crudConfigurator->configureAssets($assets);
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        yield from $this->crudConfigurator->configureFields($pageName);
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/{entityId}/preview-revert', name: 'preview_revert', options: ['methods' => ['GET']])]
    public function previewRevert(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->adminPermission);

        return $this->viewFactory->createPreviewRevertResponse($context);
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/{entityId}/revert', name: 'revert', options: ['methods' => ['POST']])]
    public function revertAuditLog(AdminContext $context): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->adminPermission);

        $request = $context->getRequest();
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST'], 'Reverting can only be performed via POST.');
        }

        $auditLog = $this->getAuditLogFromContext($context);
        if ($auditLog === null) {
            $this->addFlash('danger', 'Audit log not found.');

            return $this->viewFactory->createIndexRedirect();
        }

        if (!$this->canRevertAuditLog($auditLog)) {
            $this->addFlash('warning', 'This audit log is no longer revertable.');

            return $this->viewFactory->createIndexRedirect();
        }

        if (!$this->isCsrfTokenValid('revert', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->viewFactory->createIndexRedirect();
        }

        try {
            $this->operations->revert($auditLog);
            $this->addFlash('success', $this->viewFactory->createRevertSuccessMessage($auditLog));
        } catch (Throwable $exception) {
            $this->addFlash('danger', 'Revert failed: '.$exception->getMessage());
        }

        return $this->viewFactory->createIndexRedirect();
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/transaction-drilldown', name: 'transaction_drilldown', options: ['methods' => ['GET']])]
    public function transactionDrilldown(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->adminPermission);

        if ($context->getRequest()->query->getString('transactionHash') === '') {
            $this->addFlash('warning', 'No transaction hash provided.');

            return $this->viewFactory->createIndexRedirect();
        }

        if (!$this->operations->hasValidDrilldownCursors(
            $context->getRequest()->query->getString('afterId'),
            $context->getRequest()->query->getString('beforeId'),
        )) {
            $this->addFlash('warning', 'Invalid pagination cursor provided for transaction drill-down.');

            return $this->viewFactory->createIndexRedirect();
        }

        return $this->viewFactory->createTransactionDrilldownResponse($context, self::DRILLDOWN_LIMIT);
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/export/json', name: 'export_json', options: ['methods' => ['GET']])]
    public function exportJson(AdminContext $context): Response
    {
        return $this->doExport($context, 'json');
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/export/csv', name: 'export_csv', options: ['methods' => ['GET']])]
    public function exportCsv(AdminContext $context): Response
    {
        return $this->doExport($context, 'csv');
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    private function doExport(AdminContext $context, string $format): StreamedResponse
    {
        $this->denyAccessUnlessGranted($this->adminPermission);

        return $this->viewFactory->createExportResponse($context, $format);
    }

    #[Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $this->crudConfigurator->configureFilters($filters);
    }

    private function getAuditLogFromContext(AdminContext $context): ?AuditLog
    {
        return $this->locator->loadFromContext($context);
    }

    private function canRevertAuditLog(AuditLog $auditLog): bool
    {
        return $this->locator->isUiRevertable($auditLog);
    }
}
