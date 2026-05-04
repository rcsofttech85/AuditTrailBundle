<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminOperations;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminRequestMapper;
use Rcsofttech\AuditTrailBundle\Service\RevertPreviewFormatter;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;

final class AuditLogAdminOperationsTest extends TestCase
{
    private AuditLogRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $repository;

    private AuditExporterInterface&\PHPUnit\Framework\MockObject\MockObject $exporter;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->exporter = $this->createMock(AuditExporterInterface::class);
    }

    public function testExportToStreamAppliesAdminExportLimit(): void
    {
        $operations = new AuditLogAdminOperations(
            new class implements AuditReverterInterface {
                public function revert(
                    AuditLog $auditLog,
                    bool $dryRun = false,
                    bool $force = false,
                    array $context = [],
                    bool $silenceSubscriber = true,
                    bool $verifySignature = true,
                ): array {
                    return [];
                }
            },
            $this->repository,
            $this->exporter,
            new RevertPreviewFormatter(),
            new TransactionDrilldownService($this->repository),
            new AuditLogAdminRequestMapper(),
            2,
        );

        $audits = [
            new AuditLog('User', '1', AuditAction::Create, new DateTimeImmutable('2026-01-01 00:00:00')),
            new AuditLog('User', '2', AuditAction::Create, new DateTimeImmutable('2026-01-01 00:00:01')),
            new AuditLog('User', '3', AuditAction::Create, new DateTimeImmutable('2026-01-01 00:00:02')),
        ];

        $this->repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with(['username' => 'admin'])
            ->willReturn($audits);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        $this->exporter
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
            );

        try {
            $operations->exportToStream(['username' => 'admin'], 'json', $stream);
        } finally {
            fclose($stream);
        }
    }
}
