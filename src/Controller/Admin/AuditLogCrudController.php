<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Override;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminFieldProvider;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminLocator;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminOperations;
use Rcsofttech\AuditTrailBundle\Service\AuditLogExportResponseFactory;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

use function htmlspecialchars;
use function sprintf;

/**
 * @extends AbstractCrudController<AuditLog>
 */
final class AuditLogCrudController extends AbstractCrudController
{
    use ClassNameHelperTrait;

    private const int DRILLDOWN_LIMIT = 15;

    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly AuditLogAdminFieldProvider $fieldProvider,
        private readonly AuditLogAdminLocator $locator,
        private readonly AuditLogAdminOperations $operations,
        private readonly AuditLogExportResponseFactory $exportResponseFactory,
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
        return $crud
            ->setEntityLabelInSingular('Audit Log')
            ->setEntityLabelInPlural('Audit Logs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['entityClass', 'entityId', 'action', 'username', 'changedFields', 'transactionHash'])
            ->setPaginatorPageSize(30)
            ->overrideTemplates([
                'crud/index' => '@AuditTrail/admin/audit_log/index.html.twig',
                'crud/detail' => '@AuditTrail/admin/audit_log/detail.html.twig',
            ]);
    }

    #[Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        /** @var \EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto<AuditLog> $entityDto */
        $entityDto = $responseParameters->get('entity');
        /** @var AuditLog $log */
        $log = $entityDto->getInstance();
        $isReverted = $this->locator->isReverted($log);
        $responseParameters->set('is_reverted', $isReverted);
        $responseParameters->set('can_revert', $this->locator->isUiRevertable($log));

        return $responseParameters;
    }

    #[Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->setPermission(Action::INDEX, $this->adminPermission)
            ->setPermission(Action::DETAIL, $this->adminPermission)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[Override]
    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addCssFile('bundles/audittrail/css/audit-trail-admin.css');
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            yield from $this->fieldProvider->indexFields();

            return;
        }

        if ($pageName !== Crud::PAGE_DETAIL) {
            return;
        }

        yield from $this->fieldProvider->overviewFields();
        yield from $this->fieldProvider->changesFields();
        yield from $this->fieldProvider->technicalContextFields();
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/{entityId}/preview-revert', name: 'preview_revert', options: ['methods' => ['GET']])]
    public function previewRevert(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->adminPermission);

        $auditLog = $this->locator->loadFromContext($context);
        if ($auditLog === null) {
            return new Response('<div class="alert alert-danger"><i class="fa fa-times-circle"></i> Audit log not found.</div>', Response::HTTP_NOT_FOUND);
        }

        if (!$this->locator->isUiRevertable($auditLog)) {
            return new Response(
                '<div class="alert alert-warning"><i class="fa fa-ban"></i> This audit log is no longer revertable.</div>',
                Response::HTTP_CONFLICT,
            );
        }

        try {
            return $this->render('@AuditTrail/admin/audit_log/_revert_preview.html.twig', [
                'changes' => $this->operations->buildPreviewChanges($auditLog),
            ]);
        } catch (Throwable $e) {
            return new Response(
                sprintf('<div class="alert alert-danger shadow-sm border-0"><i class="fa fa-times-circle fs-4 mt-1"></i> <div><strong>Failed to load preview</strong><br><small>%s</small></div></div>', htmlspecialchars($e->getMessage())),
                Response::HTTP_BAD_REQUEST,
            );
        }
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

        $auditLog = $this->locator->loadFromContext($context);
        if ($auditLog === null) {
            $this->addFlash('danger', 'Audit log not found.');

            return $this->redirect($this->generateIndexUrl());
        }

        if (!$this->locator->isUiRevertable($auditLog)) {
            $this->addFlash('warning', 'This audit log is no longer revertable.');

            return $this->redirect($this->generateIndexUrl());
        }

        if (!$this->isCsrfTokenValid('revert', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateIndexUrl());
        }

        try {
            $this->operations->revert($auditLog);
            $this->addFlash('success', sprintf(
                'Successfully reverted %s #%s to its previous state.',
                $this->shortenClass($auditLog->entityClass),
                $auditLog->entityId,
            ));
        } catch (Throwable $e) {
            $this->addFlash('danger', 'Revert failed: '.$e->getMessage());
        }

        return $this->redirect($this->generateIndexUrl());
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    #[AdminRoute(path: '/transaction-drilldown', name: 'transaction_drilldown', options: ['methods' => ['GET']])]
    public function transactionDrilldown(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->adminPermission);

        $transactionHash = $context->getRequest()->query->getString('transactionHash');
        $afterId = $context->getRequest()->query->getString('afterId');
        $beforeId = $context->getRequest()->query->getString('beforeId');

        if ($transactionHash === '') {
            $this->addFlash('warning', 'No transaction hash provided.');

            return $this->redirect($this->generateIndexUrl());
        }

        if (!$this->operations->hasValidDrilldownCursors($afterId, $beforeId)) {
            $this->addFlash('warning', 'Invalid pagination cursor provided for transaction drill-down.');

            return $this->redirect($this->generateIndexUrl());
        }

        $pageData = $this->operations->getDrilldownPage($transactionHash, $afterId, $beforeId, self::DRILLDOWN_LIMIT);

        return $this->render('@AuditTrail/admin/audit_log/transaction_drilldown.html.twig', [
            'transactionHash' => $transactionHash,
            'logs' => $pageData['logs'],
            'totalItems' => $pageData['totalItems'],
            'limit' => $pageData['limit'],
            'hasNextPage' => $pageData['hasNextPage'],
            'hasPrevPage' => $pageData['hasPrevPage'],
            'firstId' => $pageData['firstId'],
            'lastId' => $pageData['lastId'],
        ]);
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

        /** @var array<string, array{value?: mixed, comparison?: string}> $filters */
        $filters = $context->getRequest()->query->all()['filters'] ?? [];

        return $this->exportResponseFactory->createResponse(
            $this->operations->mapExportFilters($filters),
            $format,
        );
    }

    #[Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('entityClass'))
            ->add(TextFilter::new('action'))
            ->add(TextFilter::new('username'))
            ->add(TextFilter::new('transactionHash'))
            ->add(DateTimeFilter::new('createdAt'));
    }

    private function generateIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
