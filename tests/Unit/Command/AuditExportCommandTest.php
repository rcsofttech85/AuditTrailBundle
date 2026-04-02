<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditExportCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

final class AuditExportCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    /** @var (AuditLogRepository&\PHPUnit\Framework\MockObject\Stub)|(AuditLogRepository&MockObject) */
    private AuditLogRepository $repository;

    /** @var (AuditExporterInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditExporterInterface&MockObject) */
    private AuditExporterInterface $exporter;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepository::class);
        $this->exporter = self::createStub(AuditExporterInterface::class);
        $this->resetCommandTester();
    }

    /** @return AuditLogRepository&MockObject */
    private function useRepositoryMock(): AuditLogRepository
    {
        $repository = self::createMock(AuditLogRepository::class);
        $this->repository = $repository;
        $this->resetCommandTester();

        return $repository;
    }

    /** @return AuditExporterInterface&MockObject */
    private function useExporterMock(): AuditExporterInterface
    {
        $exporter = self::createMock(AuditExporterInterface::class);
        $this->exporter = $exporter;
        $this->resetCommandTester();

        return $exporter;
    }

    private function resetCommandTester(): void
    {
        $this->commandTester = new CommandTester(new AuditExportCommand($this->repository, $this->exporter));
    }

    public function testExportWithNoResults(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('No audit logs found matching the criteria.', $output);

        // Verify early return — no export data is shown
        self::assertStringNotContainsString('Found', $output);
        self::assertStringNotContainsString('Exported', $output);
        self::assertStringNotContainsString('entity_class', $output);
    }

    public function testExportToJson(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create');

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('formatAudits')
            ->with([$audit], 'json')
            ->willReturn('[{"entity_class":"App\\\\Entity\\\\User"}]');

        $this->commandTester->execute([
            '--format' => 'JSON', // Test case-insensitivity
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('"entity_class"', $output);
        self::assertStringContainsString('User', $output);
        self::assertStringNotContainsString('Found 1 audit logs', $output);
    }

    public function testExportToFile(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create');
        $tempFile = sys_get_temp_dir().'/audit_export_test.json';
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 1)
            ->willReturn([$audit]);
        $repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$audit]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'json', self::isResource())
            ->willReturnCallback(static function (iterable $audits, string $_format, $stream): void {
                foreach ($audits as $audit) {
                    fwrite($stream, json_encode(['entity_class' => $audit->entityClass], JSON_THROW_ON_ERROR));
                }
            });
        $exporter
            ->expects($this->once())
            ->method('formatFileSize')
            ->with(self::greaterThan(0))
            ->willReturn('42 B');

        $this->commandTester->execute([
            '--output' => $tempFile,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Exported 1 audit logs to', $output);
        self::assertFileExists($tempFile);
        self::assertStringContainsString('"entity_class"', (string) file_get_contents($tempFile));

        unlink($tempFile);
    }

    public function testExportToFileReturnsFailureWhenWriteFails(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create');

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 1)
            ->willReturn([$audit]);

        $this->commandTester->execute([
            '--output' => sys_get_temp_dir(),
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Failed to write to file', $this->normalizeOutput($this->commandTester));
    }

    public function testExportToFileReturnsFailureWhenDirectoryCannotBeCreated(): void
    {
        $parentDirectory = sys_get_temp_dir().'/audit_export_block_'.uniqid('', true);
        self::assertTrue(mkdir($parentDirectory, 0o755, true));
        $blockingFile = $parentDirectory.'/blocking-file';
        self::assertNotFalse(file_put_contents($blockingFile, 'block'));

        try {
            set_error_handler(static function (int $severity, string $message): bool {
                return str_contains($message, 'mkdir(): File exists');
            });

            try {
                $this->commandTester->execute([
                    '--output' => $blockingFile.'/audit.json',
                ]);
            } finally {
                restore_error_handler();
            }

            self::assertSame(1, $this->commandTester->getStatusCode());
            self::assertStringContainsString('Failed to create directory', $this->normalizeOutput($this->commandTester));
        } finally {
            @unlink($blockingFile);
            @rmdir($parentDirectory);
        }
    }

    public function testExportToFileReturnsSuccessWhenPreviewFindsNoRows(): void
    {
        $tempFile = sys_get_temp_dir().'/audit_export_empty_preview.json';
        @unlink($tempFile);

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 1)
            ->willReturn([]);
        $repository
            ->expects($this->never())
            ->method('findAllWithFilters');

        $this->commandTester->execute([
            '--output' => $tempFile,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No audit logs found matching the criteria.', $this->normalizeOutput($this->commandTester));
        self::assertFileDoesNotExist($tempFile);
    }

    public function testExportToFileHonorsLimitWhenStreaming(): void
    {
        $audit1 = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create');
        $audit2 = $this->createAuditLog('018f3a3b-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '43', 'create');
        $audit3 = $this->createAuditLog('018f3a3c-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '44', 'create');
        $tempFile = sys_get_temp_dir().'/audit_export_limit_test.json';
        @unlink($tempFile);

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 1)
            ->willReturn([$audit1]);
        $repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$audit1, $audit2, $audit3]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'json', self::isResource())
            ->willReturnCallback(static function (iterable $audits, string $_format, $stream): void {
                $payload = [];
                foreach ($audits as $audit) {
                    $payload[] = $audit->entityId;
                }

                fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));
            });
        $exporter
            ->expects($this->once())
            ->method('formatFileSize')
            ->with(self::greaterThan(0))
            ->willReturn('16 B');

        try {
            $this->commandTester->execute([
                '--output' => $tempFile,
                '--limit' => '2',
            ]);

            self::assertSame(0, $this->commandTester->getStatusCode());
            self::assertSame('["42","43"]', (string) file_get_contents($tempFile));
            self::assertStringContainsString('Exported 3 audit logs', $this->normalizeOutput($this->commandTester));
        } finally {
            @unlink($tempFile);
        }
    }

    public function testExportToCsv(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'update');

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('formatAudits')
            ->with([$audit], 'csv')
            ->willReturn("id,entity_class,entity_id,action\n1,App\\Entity\\User,42,update\n");

        $this->commandTester->execute([
            '--format' => 'csv',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('id,entity_class', $output);
        self::assertStringContainsString('update', $output);
        self::assertStringNotContainsString('Found 1 audit logs', $output);
    }

    public function testExportWithInvalidFormat(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--format' => 'xml',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid format', $this->normalizeOutput($this->commandTester));
    }

    public function testExportWithLimitBoundaries(): void
    {
        // Test lower boundary
        $this->commandTester->execute(['--limit' => '0']);
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between 1 and 100000', $this->normalizeOutput($this->commandTester));

        // Test upper boundary
        $this->commandTester->execute(['--limit' => '100001']);
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between 1 and 100000', $this->normalizeOutput($this->commandTester));
    }

    public function testExportWithInvalidAction(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--action' => 'invalid_action',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid action', $this->normalizeOutput($this->commandTester));
    }

    public function testExportWithFilters(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return $filters['entityClass'] === 'User'
                        && $filters['action'] === 'create'
                        && $filters['from'] instanceof DateTimeImmutable
                        && $filters['to'] instanceof DateTimeImmutable;
                }),
                1000
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--entity' => 'User',
            '--action' => 'create',
            '--from' => '2024-01-01',
            '--to' => '2024-01-02',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExportWithEmptyFilters(): void
    {
        // Empty strings should be ignored
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 1000)
            ->willReturn([]);

        $this->commandTester->execute([
            '--entity' => '',
            '--action' => '',
            '--from' => '',
            '--to' => '',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    private function createAuditLog(string $id, string $entityClass, string $entityId, string $action): AuditLog
    {
        $log = new AuditLog($entityClass, $entityId, $action, new DateTimeImmutable('2024-01-01 12:00:00'));
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));

        return $log;
    }

    /**
     * Test that invalid from date fails with proper error.
     */
    public function testExportWithInvalidFromDate(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--from' => 'not-a-valid-date',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Invalid "from" date', $output);
    }

    /**
     * Test that invalid to date fails with proper error.
     */
    public function testExportWithInvalidToDate(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--to' => 'invalid-date-format',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Invalid "to" date', $output);
    }
}
