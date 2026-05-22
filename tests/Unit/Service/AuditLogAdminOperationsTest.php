<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminOperations;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminRequestMapper;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\RevertPreviewFormatter;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;
use RuntimeException;

final class AuditLogAdminOperationsTest extends TestCase
{
    public function testExportToStreamAppliesAdminExportLimit(): void
    {
        $repository = self::createMock(AuditLogRepositoryInterface::class);
        $exporter = self::createMock(AuditExporterInterface::class);

        $operations = new AuditLogAdminOperations(
            $this->createNoOpReverter(),
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        $audits = [
            new AuditLog('User', '1', AuditAction::Create, new DateTimeImmutable('2026-01-01 00:00:00')),
            new AuditLog('User', '2', AuditAction::Create, new DateTimeImmutable('2026-01-01 00:00:01')),
            new AuditLog('User', '3', AuditAction::Create, new DateTimeImmutable('2026-01-01 00:00:02')),
        ];

        $repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with(['username' => 'admin'])
            ->willReturn($audits);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        $exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(
                self::callback(static function (iterable $exportedAudits) use ($audits): bool {
                    $collected = [];
                    foreach ($exportedAudits as $audit) {
                        $collected[] = $audit;
                    }

                    return $collected === [$audits[0], $audits[1]];
                }),
                'json',
                $stream,
            )
            ->willReturn(2);

        try {
            $operations->exportToStream(['username' => 'admin'], 'json', $stream);
        } finally {
            fclose($stream);
        }
    }

    public function testBuildPreviewChangesFormatsValuesReturnedByTheReverter(): void
    {
        $auditLog = new AuditLog('User', '1', AuditAction::Update, new DateTimeImmutable('2026-01-01 00:00:00'));
        $repository = $this->createNoOpRepository();
        $exporter = $this->createNoOpExporter();
        $reverter = self::createStub(AuditReverterInterface::class);
        $reverter->method('revert')
            ->willReturn([
                'timestamp' => new DateTimeImmutable('2026-01-02 12:34:56'),
                'status' => 'archived',
            ]);

        $operations = new AuditLogAdminOperations(
            $reverter,
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        self::assertSame([
            'timestamp' => '2026-01-02 12:34:56',
            'status' => 'archived',
        ], $operations->buildPreviewChanges($auditLog));
    }

    public function testRevertForcesTheUnderlyingReverter(): void
    {
        $auditLog = new AuditLog('User', '1', AuditAction::Update, new DateTimeImmutable('2026-01-01 00:00:00'));
        $repository = $this->createNoOpRepository();
        $exporter = $this->createNoOpExporter();
        $reverter = self::createMock(AuditReverterInterface::class);
        $reverter->expects(self::once())
            ->method('revert')
            ->with($auditLog, false, true, [], true, true)
            ->willReturn([]);

        $operations = new AuditLogAdminOperations(
            $reverter,
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        $operations->revert($auditLog);
    }

    public function testGetDrilldownPageDelegatesToTheTransactionService(): void
    {
        $audit = new AuditLog('User', '1', AuditAction::Update, new DateTimeImmutable('2026-01-01 00:00:00'));
        $repository = self::createMock(AuditLogRepositoryInterface::class);

        $repository->expects($this->once())
            ->method('count')
            ->with(['transactionHash' => 'tx-1'])
            ->willReturn(1);
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => 'tx-1'], 3)
            ->willReturn([$audit]);

        $operations = new AuditLogAdminOperations(
            $this->createNoOpReverter(),
            $repository,
            $this->createNoOpExporter(),
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        $page = $operations->getDrilldownPage('tx-1', '', '', 2);

        self::assertSame([$audit], $page['logs']);
        self::assertSame(1, $page['totalItems']);
        self::assertSame(2, $page['limit']);
    }

    public function testHasValidDrilldownCursorsRejectsInvalidAndConflictingValues(): void
    {
        $repository = $this->createNoOpRepository();
        $exporter = $this->createNoOpExporter();

        $operations = new AuditLogAdminOperations(
            $this->createNoOpReverter(),
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        self::assertTrue($operations->hasValidDrilldownCursors('', ''));
        self::assertFalse($operations->hasValidDrilldownCursors('not-a-uuid', ''));
        self::assertFalse($operations->hasValidDrilldownCursors(
            '019f0a54-27cb-7d60-bdf8-3229793f8d11',
            '019f0a54-27cc-7fa1-9c89-a1d98ba49bb7',
        ));
    }

    public function testMapExportFiltersDelegatesToTheRequestMapper(): void
    {
        $repository = $this->createNoOpRepository();
        $exporter = $this->createNoOpExporter();

        $operations = new AuditLogAdminOperations(
            $this->createNoOpReverter(),
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        $filters = $operations->mapExportFilters([
            'username' => ['value' => 'admin'],
            'createdAt' => [
                'comparison' => 'between',
                'value' => '2026-01-01',
                'value2' => '2026-01-31',
            ],
            'ignored' => ['value' => ''],
        ]);

        self::assertSame([
            'username' => 'admin',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ], $filters);
    }

    public function testExportToStreamRejectsNonResourceOutputs(): void
    {
        $repository = $this->createNoOpRepository();
        $exporter = $this->createNoOpExporter();

        $operations = new AuditLogAdminOperations(
            $this->createNoOpReverter(),
            $repository,
            $exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected a writable stream resource for export.');

        $operations->exportToStream([], 'json', $this->invalidOutput());
    }

    private function createNoOpReverter(): AuditReverterInterface
    {
        return self::createStub(AuditReverterInterface::class);
    }

    private function createNoOpRepository(): AuditLogRepositoryInterface
    {
        return self::createStub(AuditLogRepositoryInterface::class);
    }

    private function createNoOpExporter(): AuditExporterInterface
    {
        return self::createStub(AuditExporterInterface::class);
    }

    private function invalidOutput(): mixed
    {
        return 'not-a-resource';
    }
}
