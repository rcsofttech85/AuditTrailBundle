<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Rcsofttech\AuditTrailBundle\Controller\Admin\AuditLogCrudController;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Twig\Environment;

use function htmlspecialchars;
use function sprintf;

/**
 * Builds admin responses so the controller stays thin.
 */
final readonly class AuditLogAdminViewFactory
{
    use ClassNameHelperTrait;

    public function __construct(
        private AuditLogAdminLocator $locator,
        private AuditLogAdminOperations $operations,
        private AuditLogExportResponseFactory $exportResponseFactory,
        private AdminUrlGenerator $adminUrlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    public function createPreviewRevertResponse(AdminContext $context): Response
    {
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
            return new Response($this->twig->render('@AuditTrail/admin/audit_log/_revert_preview.html.twig', [
                'changes' => $this->operations->buildPreviewChanges($auditLog),
            ]));
        } catch (Throwable $exception) {
            return new Response(
                sprintf('<div class="alert alert-danger shadow-sm border-0"><i class="fa fa-times-circle fs-4 mt-1"></i> <div><strong>Failed to load preview</strong><br><small>%s</small></div></div>', htmlspecialchars($exception->getMessage())),
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    public function createTransactionDrilldownResponse(AdminContext $context, int $limit): Response|RedirectResponse
    {
        $transactionHash = $context->getRequest()->query->getString('transactionHash');
        $afterId = $context->getRequest()->query->getString('afterId');
        $beforeId = $context->getRequest()->query->getString('beforeId');

        if ($transactionHash === '') {
            return $this->createIndexRedirect();
        }

        if (!$this->operations->hasValidDrilldownCursors($afterId, $beforeId)) {
            return $this->createIndexRedirect();
        }

        $pageData = $this->operations->getDrilldownPage($transactionHash, $afterId, $beforeId, $limit);

        return new Response($this->twig->render('@AuditTrail/admin/audit_log/transaction_drilldown.html.twig', [
            'transactionHash' => $transactionHash,
            'logs' => $pageData['logs'],
            'totalItems' => $pageData['totalItems'],
            'limit' => $pageData['limit'],
            'hasNextPage' => $pageData['hasNextPage'],
            'hasPrevPage' => $pageData['hasPrevPage'],
            'firstId' => $pageData['firstId'],
            'lastId' => $pageData['lastId'],
        ]));
    }

    /**
     * @param AdminContext<AuditLog> $context
     */
    public function createExportResponse(AdminContext $context, string $format): StreamedResponse
    {
        /** @var array<string, array{value?: mixed, comparison?: string}> $filters */
        $filters = $context->getRequest()->query->all()['filters'] ?? [];

        return $this->exportResponseFactory->createResponse(
            $this->operations->mapExportFilters($filters),
            $format,
        );
    }

    public function createIndexRedirect(): RedirectResponse
    {
        return new RedirectResponse(
            $this->adminUrlGenerator
                ->setController(AuditLogCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl()
        );
    }

    public function createRevertSuccessMessage(AuditLog $auditLog): string
    {
        return sprintf(
            'Successfully reverted %s #%s to its previous state.',
            $this->shortenClass($auditLog->entityClass),
            $auditLog->entityId,
        );
    }
}
