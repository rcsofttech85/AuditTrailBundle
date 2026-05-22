<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use EasyCorp\Bundle\EasyAdminBundle\Config\Option\CacheKey;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\RequestContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Registry\AdminControllerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminLocator;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminOperations;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminRequestMapper;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminViewFactory;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogExportResponseFactory;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\RevertPreviewFormatter;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

final class AuditLogAdminViewFactoryTest extends TestCase
{
    public function testCreatePreviewRevertResponseReturnsNotFoundWhenAuditLogDoesNotExist(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('find')
            ->with('42')
            ->willReturn(null);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $factory = $this->createViewFactory(
            $repository,
            $this->createNoOpReverter(),
            $this->createNoOpExporter(),
            $twig,
        );

        $response = $factory->createPreviewRevertResponse($this->createContext(['entityId' => '42']));
        $content = $response->getContent();

        self::assertSame(404, $response->getStatusCode());
        self::assertIsString($content);
        self::assertStringContainsString('Audit log not found', $content);
    }

    public function testCreatePreviewRevertResponseReturnsConflictWhenAuditIsNotUiRevertable(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('find')
            ->with('42')
            ->willReturn($this->createAuditLog(AuditAction::Delete));

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $factory = $this->createViewFactory(
            $repository,
            $this->createNoOpReverter(),
            $this->createNoOpExporter(),
            $twig,
        );

        $response = $factory->createPreviewRevertResponse($this->createContext(['entityId' => '42']));
        $content = $response->getContent();

        self::assertSame(409, $response->getStatusCode());
        self::assertIsString($content);
        self::assertStringContainsString('no longer revertable', $content);
    }

    public function testCreatePreviewRevertResponseRendersFormattedPreviewChanges(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $log = $this->createAuditLog();

        $repository->expects(self::once())
            ->method('find')
            ->with('42')
            ->willReturn($log);
        $repository->expects(self::once())
            ->method('isReverted')
            ->with($log)
            ->willReturn(false);
        $repository->expects(self::once())
            ->method('hasNewerStateChangingLogs')
            ->with($log)
            ->willReturn(false);
        $reverter = self::createMock(AuditReverterInterface::class);
        $reverter->expects(self::once())
            ->method('revert')
            ->with($log, true, true, [], true, true)
            ->willReturn(['status' => 'archived']);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@AuditTrail/admin/audit_log/_revert_preview.html.twig',
                ['changes' => ['status' => 'archived']]
            )
            ->willReturn('<div>preview</div>');

        $factory = $this->createViewFactory(
            $repository,
            $reverter,
            $this->createNoOpExporter(),
            $twig,
        );

        $response = $factory->createPreviewRevertResponse($this->createContext(['entityId' => '42']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<div>preview</div>', $response->getContent());
    }

    public function testCreatePreviewRevertResponseReturnsBadRequestWhenPreviewGenerationFails(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $log = $this->createAuditLog();

        $repository->expects(self::once())
            ->method('find')
            ->with('42')
            ->willReturn($log);
        $repository->expects(self::once())
            ->method('isReverted')
            ->with($log)
            ->willReturn(false);
        $repository->expects(self::once())
            ->method('hasNewerStateChangingLogs')
            ->with($log)
            ->willReturn(false);
        $reverter = self::createMock(AuditReverterInterface::class);
        $reverter->expects(self::once())
            ->method('revert')
            ->with($log, true, true, [], true, true)
            ->willThrowException(new RuntimeException('<broken preview>'));

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $factory = $this->createViewFactory(
            $repository,
            $reverter,
            $this->createNoOpExporter(),
            $twig,
        );

        $response = $factory->createPreviewRevertResponse($this->createContext(['entityId' => '42']));
        $content = $response->getContent();

        self::assertSame(400, $response->getStatusCode());
        self::assertIsString($content);
        self::assertStringContainsString('&lt;broken preview&gt;', $content);
    }

    public function testCreateTransactionDrilldownResponseRedirectsOnMissingHashOrInvalidCursors(): void
    {
        $repository = self::createStub(AuditLogRepositoryInterface::class);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $factory = $this->createViewFactory(
            $repository,
            $this->createNoOpReverter(),
            $this->createNoOpExporter(),
            $twig,
        );

        $missingHashResponse = $factory->createTransactionDrilldownResponse($this->createContext([]), 15);
        self::assertInstanceOf(RedirectResponse::class, $missingHashResponse);
        self::assertSame('/admin/audit-logs', $missingHashResponse->getTargetUrl());

        $invalidCursorResponse = $factory->createTransactionDrilldownResponse($this->createContext([
            'transactionHash' => 'tx-1',
            'afterId' => Uuid::v7()->toRfc4122(),
            'beforeId' => Uuid::v7()->toRfc4122(),
        ]), 15);
        self::assertInstanceOf(RedirectResponse::class, $invalidCursorResponse);
        self::assertSame('/admin/audit-logs', $invalidCursorResponse->getTargetUrl());
    }

    public function testCreateTransactionDrilldownResponseRendersPageData(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $log = $this->createAuditLog();
        $log->initializeIdIfMissing(Uuid::fromString('019f0a54-27cb-7d60-bdf8-3229793f8d11'));

        $repository->expects(self::once())
            ->method('count')
            ->with(['transactionHash' => 'tx-42'])
            ->willReturn(1);
        $repository->expects(self::once())
            ->method('findWithFilters')
            ->with(['transactionHash' => 'tx-42'], 16)
            ->willReturn([$log]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@AuditTrail/admin/audit_log/transaction_drilldown.html.twig',
                self::callback(static function (array $context) use ($log): bool {
                    return $context['transactionHash'] === 'tx-42'
                        && $context['logs'] === [$log]
                        && $context['totalItems'] === 1
                        && $context['limit'] === 15
                        && $context['hasNextPage'] === false
                        && $context['hasPrevPage'] === false
                        && $context['firstId'] === '019f0a54-27cb-7d60-bdf8-3229793f8d11'
                        && $context['lastId'] === '019f0a54-27cb-7d60-bdf8-3229793f8d11';
                })
            )
            ->willReturn('<div>drilldown</div>');

        $factory = $this->createViewFactory(
            $repository,
            $this->createNoOpReverter(),
            $this->createNoOpExporter(),
            $twig,
        );

        $response = $factory->createTransactionDrilldownResponse($this->createContext([
            'transactionHash' => 'tx-42',
            'afterId' => '',
            'beforeId' => '',
        ]), 15);

        self::assertSame('<div>drilldown</div>', $response->getContent());
    }

    public function testCreateExportResponseStreamsMappedFilters(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $audit = $this->createAuditLog();

        $repository->expects(self::once())
            ->method('findAllWithFilters')
            ->with([
                'username' => 'admin',
                'from' => '2026-01-01',
                'to' => '2026-01-31',
            ])
            ->willReturn([$audit]);

        $exporter = self::createMock(AuditExporterInterface::class);
        $exporter->expects(self::once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'csv', self::isResource())
            ->willReturnCallback(static function (iterable $audits, string $format, mixed $stream): int {
                fwrite($stream, 'streamed-'.$format);

                return 1;
            });

        $factory = $this->createViewFactory(
            $repository,
            $this->createNoOpReverter(),
            $exporter,
            self::createStub(Environment::class),
        );

        $response = $factory->createExportResponse($this->createContext([
            'filters' => [
                'username' => ['value' => 'admin'],
                'createdAt' => [
                    'comparison' => 'between',
                    'value' => '2026-01-01',
                    'value2' => '2026-01-31',
                ],
            ],
        ]), 'csv');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        self::assertSame('text/csv', $response->headers->get('Content-Type'));
        self::assertNotNull($response->headers->get('Content-Disposition'));
        self::assertStringContainsString('attachment; filename="audit_logs_', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('.csv"', $response->headers->get('Content-Disposition'));
        self::assertSame('streamed-csv', $content);
    }

    public function testCreateRevertSuccessMessageUsesShortEntityName(): void
    {
        $factory = $this->createViewFactory(
            self::createStub(AuditLogRepositoryInterface::class),
            $this->createNoOpReverter(),
            $this->createNoOpExporter(),
            self::createStub(Environment::class),
        );

        self::assertSame(
            'Successfully reverted Order #42 to its previous state.',
            $factory->createRevertSuccessMessage($this->createAuditLog())
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return AdminContext<AuditLog>
     */
    private function createContext(array $query): AdminContext
    {
        return AdminContext::forTesting(
            RequestContext::forTesting(new Request($query)),
        );
    }

    private function createViewFactory(
        AuditLogRepositoryInterface $repository,
        AuditReverterInterface $reverter,
        AuditExporterInterface $exporter,
        Environment $twig,
    ): AuditLogAdminViewFactory {
        $operations = new AuditLogAdminOperations(
            $reverter,
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            50,
        );

        return new AuditLogAdminViewFactory(
            new AuditLogAdminLocator($repository),
            $operations,
            new AuditLogExportResponseFactory($operations),
            $this->createAdminUrlGenerator(),
            $twig,
        );
    }

    private function createAdminUrlGenerator(): AdminUrlGenerator
    {
        $adminContextProvider = self::createStub(AdminContextProviderInterface::class);
        $adminContextProvider->method('getContext')->willReturn(null);

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/admin/audit-logs');

        $cache = new ArrayAdapter();
        $dashboardRoutes = $cache->getItem(CacheKey::DASHBOARD_FQCN_TO_ROUTE);
        $dashboardRoutes->set([self::class => 'admin_dashboard']);
        $cache->save($dashboardRoutes);

        $adminRouteGenerator = self::createStub(AdminRouteGeneratorInterface::class);
        $adminRouteGenerator->method('findRouteName')->willReturn('audit_log_index_route');
        $adminRouteGenerator->method('getDashboardRoutes')->willReturn([self::class => 'admin_dashboard']);

        return new AdminUrlGenerator(
            $adminContextProvider,
            $urlGenerator,
            new AdminControllerRegistry($cache),
            $adminRouteGenerator,
            $cache,
        );
    }

    private function createNoOpReverter(): AuditReverterInterface
    {
        return self::createStub(AuditReverterInterface::class);
    }

    private function createNoOpExporter(): AuditExporterInterface
    {
        return self::createStub(AuditExporterInterface::class);
    }

    private function createAuditLog(AuditAction $action = AuditAction::Update): AuditLog
    {
        return new AuditLog(
            'App\\Entity\\Order',
            '42',
            $action,
        );
    }
}
