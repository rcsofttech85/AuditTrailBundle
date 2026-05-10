<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditExportFileWriter;
use Rcsofttech\AuditTrailBundle\Service\AuditExportInput;
use Rcsofttech\AuditTrailBundle\Service\AuditExportService;

use function count;

use const JSON_THROW_ON_ERROR;

final class AuditExportServiceTest extends TestCase
{
    private AuditLogRepositoryInterface&MockObject $repository;

    private AuditExporterInterface&MockObject $exporter;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->exporter = $this->createMock(AuditExporterInterface::class);
    }

    public function testExportReturnsEmptyResultWithoutWriting(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([]);
        $this->exporter
            ->expects($this->never())
            ->method('exportToStream');

        $service = new AuditExportService($this->repository, $this->exporter, new AuditExportFileWriter());
        $result = $service->export(new AuditExportInput('php://output', 'json', 100, []));

        self::assertSame(0, $result->count);
        self::assertNull($result->size);
    }

    public function testExportStreamsLimitedFileOutput(): void
    {
        $audits = [
            new AuditLog('User', '1', 'create'),
            new AuditLog('User', '2', 'create'),
            new AuditLog('User', '3', 'create'),
        ];
        $outputFile = sys_get_temp_dir().'/audit_export_service_test.json';
        $this->removeFileIfExists($outputFile);

        $this->repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with(['entityClass' => 'User'])
            ->willReturn($audits);
        $this->exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'json', self::isResource())
            ->willReturnCallback(static function (iterable $batch, string $_format, $stream): int {
                $payload = [];
                foreach ($batch as $audit) {
                    $payload[] = $audit->entityId;
                }

                fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));

                return count($payload);
            });
        $this->exporter
            ->expects($this->once())
            ->method('formatFileSize')
            ->with(self::greaterThan(0))
            ->willReturn('10 B');

        try {
            $service = new AuditExportService($this->repository, $this->exporter, new AuditExportFileWriter());
            $result = $service->export(new AuditExportInput($outputFile, 'json', 2, ['entityClass' => 'User']));

            self::assertSame(2, $result->count);
            self::assertSame('10 B', $result->formattedSize);
            self::assertStringContainsString('["1","2"]', (string) file_get_contents($outputFile));
        } finally {
            $this->removeFileIfExists($outputFile);
        }
    }

    private function removeFileIfExists(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        self::assertTrue(unlink($path));
    }
}
