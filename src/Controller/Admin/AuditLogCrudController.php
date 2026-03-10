<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Controller\Admin;

use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\RevertPreviewFormatter;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Stringable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function is_scalar;
use function sprintf;

/**
 * Provides a rich, read-only view of audit logs in EasyAdmin with:
 * - Visual diff view for changes
 * - One-click revert with dry-run preview
 * - Transaction drill-down
 * - Structured context view
 *
 * @codeCoverageIgnore
 *
 * @extends AbstractCrudController<AuditLog>
 */
class AuditLogCrudController extends AbstractCrudController
{
    use ClassNameHelperTrait;

    private const int DRILLDOWN_LIMIT = 15;

    public function __construct(
        private readonly AuditReverterInterface $reverter,
        private readonly AuditLogRepositoryInterface $repository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly AuditIntegrityServiceInterface $integrityService,
        private readonly AuditExporterInterface $exporter,
        private readonly RevertPreviewFormatter $formatter,
        private readonly TransactionDrilldownService $drilldownService,
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
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            /** @var \EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto<AuditLog> $entityDto */
            $entityDto = $responseParameters->get('entity');
            /** @var AuditLog $log */
            $log = $entityDto->getInstance();
            $responseParameters->set('is_reverted', $this->repository->isReverted($log));
        }

        return $responseParameters;
    }

    #[Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
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
        yield from $this->configureIndexFields();
        yield from $this->configureOverviewTabFields();
        yield from $this->configureChangesTabFields();
        yield from $this->configureTechnicalContextTabFields();
    }

    /**
     * Preview revert changes (dry-run) — returns HTML fragment for the modal.
     *
     * @param AdminContext<AuditLog> $context
     */
    public function previewRevert(AdminContext $context): Response
    {
        $auditLog = $this->loadEntityFromContext($context);

        if ($auditLog === null) {
            return new Response('<div class="alert alert-danger"><i class="fa fa-times-circle"></i> Audit log not found.</div>', Response::HTTP_NOT_FOUND);
        }

        try {
            // Optimization: Skip signature verification for preview (it's checked on final revert anyway)
            $changes = $this->reverter->revert($auditLog, dryRun: true, force: true, verifySignature: false);

            $formattedChanges = [];
            foreach ($changes as $field => $value) {
                $formattedChanges[$field] = $this->formatter->format($value);
            }

            return $this->render('@AuditTrail/admin/audit_log/_revert_preview.html.twig', [
                'changes' => $formattedChanges,
            ]);
        } catch (Throwable $e) {
            return new Response(
                sprintf('<div class="alert alert-danger shadow-sm border-0"><i class="fa fa-times-circle fs-4 mt-1"></i> <div><strong>Failed to load preview</strong><br><small>%s</small></div></div>', htmlspecialchars($e->getMessage())),
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * Execute the revert operation.
     *
     * @param AdminContext<AuditLog> $context
     */
    public function revertAuditLog(AdminContext $context): RedirectResponse
    {
        $auditLog = $this->loadEntityFromContext($context);

        if ($auditLog === null) {
            $this->addFlash('danger', 'Audit log not found.');

            return $this->redirect($this->generateIndexUrl());
        }

        if (!$this->isCsrfTokenValid('revert', $context->getRequest()->request->getString('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateIndexUrl());
        }

        try {
            $this->reverter->revert($auditLog, force: true);
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
     * Transaction drill-down — displays all logs with the same transaction hash.
     *
     * @param AdminContext<AuditLog> $context
     */
    public function transactionDrilldown(AdminContext $context): Response
    {
        $transactionHash = $context->getRequest()->query->getString('transactionHash');
        $afterId = $context->getRequest()->query->getString('afterId');
        $beforeId = $context->getRequest()->query->getString('beforeId');

        if ($transactionHash === '') {
            $this->addFlash('warning', 'No transaction hash provided.');

            return $this->redirect($this->generateIndexUrl());
        }

        $pageData = $this->drilldownService->getDrilldownPage($transactionHash, $afterId, $beforeId, self::DRILLDOWN_LIMIT);

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
    public function exportJson(AdminContext $context): Response
    {
        return $this->doExport($context, 'json');
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    public function exportCsv(AdminContext $context): Response
    {
        return $this->doExport($context, 'csv');
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    private function doExport(AdminContext $context, string $format): Response
    {
        $filters = $this->getFiltersFromRequest($context);
        /** @var iterable<AuditLog> $audits */
        $audits = $this->repository->findAllWithFilters($filters);

        $content = $this->exporter->formatAudits($audits, $format);
        $fileName = sprintf('audit_logs_%s.%s', new DateTimeImmutable()->format('Y-m-d_His'), $format);

        $response = new Response($content);
        $response->headers->set('Content-Type', $format === 'json' ? 'application/json' : 'text/csv');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $fileName));

        return $response;
    }

    /**
     * @param AdminContext<AuditLog> $context
     *
     * @return array<string, mixed>
     */
    private function getFiltersFromRequest(AdminContext $context): array
    {
        $request = $context->getRequest();
        /** @var array<string, array{value?: mixed, comparison?: string}> $filters */
        $filters = $request->query->all()['filters'] ?? [];
        $processedFilters = [];

        foreach ($filters as $property => $data) {
            if (isset($data['value']) && $data['value'] !== '') {
                $processedFilters[$property] = $data['value'];
            }
        }

        return $processedFilters;
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

    /**
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    private function configureIndexFields(): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield ChoiceField::new('action', 'Action')
            ->setChoices([
                'Create' => AuditLogInterface::ACTION_CREATE,
                'Update' => AuditLogInterface::ACTION_UPDATE,
                'Delete' => AuditLogInterface::ACTION_DELETE,
                'Soft Delete' => AuditLogInterface::ACTION_SOFT_DELETE,
                'Restore' => AuditLogInterface::ACTION_RESTORE,
                'Revert' => AuditLogInterface::ACTION_REVERT,
                'Access' => AuditLogInterface::ACTION_ACCESS,
            ])
            ->renderAsBadges([
                AuditLogInterface::ACTION_CREATE => 'success',
                AuditLogInterface::ACTION_UPDATE => 'warning',
                AuditLogInterface::ACTION_DELETE => 'danger',
                AuditLogInterface::ACTION_SOFT_DELETE => 'danger',
                AuditLogInterface::ACTION_RESTORE => 'info',
                AuditLogInterface::ACTION_REVERT => 'primary',
                AuditLogInterface::ACTION_ACCESS => 'secondary',
            ])
            ->onlyOnIndex();

        yield TextField::new('entityClass', 'Entity')
            ->formatValue(fn ($value): string => $this->shortenClass((string) (is_scalar($value) || $value instanceof Stringable ? $value : '')))
            ->setHelp('The PHP class of the modified entity')
            ->onlyOnIndex();

        yield TextField::new('entityId', 'Entity ID')->onlyOnIndex();
        yield TextField::new('username', 'User')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Occurred At')
            ->setFormat('dd MMM yyyy | HH:mm:ss')
            ->onlyOnIndex();
    }

    /**
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    private function configureOverviewTabFields(): iterable
    {
        yield FormField::addTab('Overview')->setIcon('fa fa-info-circle');
        yield FormField::addPanel()->setHelp('Basic information about the audit event.');

        yield IdField::new('id', 'Audit Log ID')->onlyOnDetail();
        yield TextField::new('entityClass', 'Entity Class')->onlyOnDetail();
        yield TextField::new('entityId', 'Entity ID')->onlyOnDetail();
        yield TextField::new('action', 'Action Type')->onlyOnDetail();

        yield FormField::addRow();
        yield TextField::new('username', 'Performed By')->onlyOnDetail()->setColumns(6);
        yield DateTimeField::new('createdAt', 'Timestamp')
            ->setFormat('dd MMM yyyy | HH:mm:ss')
            ->onlyOnDetail()
            ->setColumns(6);

        yield FormField::addRow();
        yield TextField::new('ipAddress', 'IP Address')
            ->formatValue(static fn ($value): string => ($value !== null && $value !== '') ? (string) (is_scalar($value) || $value instanceof Stringable ? $value : '') : 'N/A')
            ->renderAsHtml()
            ->onlyOnDetail()
            ->setColumns(6);

        yield TextField::new('userAgent', 'User Agent')->onlyOnDetail()->setColumns(6);
    }

    /**
     * Changed from raw CodeEditorField to a custom diff view template.
     *
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    private function configureChangesTabFields(): iterable
    {
        yield FormField::addTab('Changes')->setIcon('fa fa-exchange-alt');
        yield FormField::addPanel()->setHelp('Visual comparison of the entity state before and after the change.');

        yield TextField::new('signature', 'Integrity Signature')
            ->formatValue(function ($value, AuditLog $log): string {
                if (!$this->integrityService->isEnabled()) {
                    return '<span class="badge badge-secondary text-muted"><i class="fa fa-shield-alt me-1"></i> Integrity Disabled</span>';
                }

                if ($this->integrityService->verifySignature($log)) {
                    return '<span class="badge badge-success text-success" style="background: rgba(25, 135, 84, 0.1);"><i class="fa fa-check-circle me-1"></i> Verified Authentic</span>';
                }

                return '<span class="badge badge-danger text-danger" style="background: rgba(220, 53, 69, 0.1);"><i class="fa fa-times-circle me-1"></i> Tampered / Invalid</span>';
            })
            ->renderAsHtml()
            ->onlyOnDetail();

        yield FormField::addRow();
        yield CodeEditorField::new('changedFields', 'Visual Diff')
            ->setTemplatePath('@AuditTrail/admin/audit_log/field/diff_view.html.twig')
            ->setColumns(12)
            ->onlyOnDetail();
    }

    /**
     * Technical Context tab — uses transaction link + structured context templates.
     *
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    private function configureTechnicalContextTabFields(): iterable
    {
        yield FormField::addTab('Context')->setIcon('fa fa-cogs');
        yield FormField::addPanel()->setHelp('Low-level transaction details and custom context metadata.');

        yield TextField::new('transactionHash', 'Transaction Hash')
            ->setTemplatePath('@AuditTrail/admin/audit_log/field/transaction_link.html.twig')
            ->onlyOnDetail()
            ->setHelp('Click to see all changes in this transaction.');

        yield CodeEditorField::new('context', 'Context Details')
            ->setTemplatePath('@AuditTrail/admin/audit_log/field/context_view.html.twig')
            ->onlyOnDetail()
            ->setHelp('Structured view of context metadata.');
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    private function loadEntityFromContext(AdminContext $context): ?AuditLog
    {
        $entityId = $context->getRequest()->query->getString('entityId');

        if ($entityId === '') {
            return null;
        }

        /** @var AuditLog|null $auditLog */
        $auditLog = $this->repository->find($entityId);

        return $auditLog;
    }

    private function generateIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->setController(static::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
