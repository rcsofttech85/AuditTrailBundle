<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditExportCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditExporterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditExportFileWriter;
use Rcsofttech\AuditTrailBundle\Service\AuditExportInputFactory;
use Rcsofttech\AuditTrailBundle\Service\AuditExportService;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

use function count;

use const JSON_THROW_ON_ERROR;

final class AuditExportCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    /** @var (AuditLogRepositoryInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditLogRepositoryInterface&MockObject) */
    private AuditLogRepositoryInterface $repository;

    /** @var (AuditExporterInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditExporterInterface&MockObject) */
    private AuditExporterInterface $exporter;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->exporter = self::createStub(AuditExporterInterface::class);
        $this->resetCommandTester();
    }

    /** @return AuditLogRepositoryInterface&MockObject */
    private function useRepositoryMock(): AuditLogRepositoryInterface
    {
        $repository = self::createMock(AuditLogRepositoryInterface::class);
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
        $this->commandTester = new CommandTester(new AuditExportCommand(
            new AuditExportInputFactory(),
            new AuditExportService($this->repository, $this->exporter, new AuditExportFileWriter()),
        ));
    }

    public function testExportWithNoResults(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
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
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$audit]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'json', self::isResource())
            ->willReturnCallback(static function (iterable $audits, string $_format, $stream): int {
                foreach ($audits as $audit) {
                    fwrite($stream, json_encode(['entity_class' => $audit->entityClass], JSON_THROW_ON_ERROR));
                }

                return 1;
            });

        ob_start();
        try {
            $this->commandTester->execute([
                '--format' => 'JSON', // Test case-insensitivity
            ]);
            $streamOutput = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('"entity_class"', $streamOutput);
        self::assertStringContainsString('User', $streamOutput);
        self::assertStringNotContainsString('Found 1 audit logs', $streamOutput);
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
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$audit]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'json', self::isResource())
            ->willReturnCallback(static function (iterable $audits, string $_format, $stream): int {
                $count = 0;
                foreach ($audits as $audit) {
                    fwrite($stream, json_encode(['entity_class' => $audit->entityClass], JSON_THROW_ON_ERROR));
                    ++$count;
                }

                return $count;
            });

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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create')]);

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
            $repository = $this->useRepositoryMock();
            $repository
                ->expects($this->once())
                ->method('findAllWithFilters')
                ->with([])
                ->willReturn([$this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create')]);

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
            $this->removeFileIfExists($blockingFile);
            $this->removeDirectoryIfExists($parentDirectory);
        }
    }

    public function testExportToFileReturnsSuccessWhenStreamingFindsNoRows(): void
    {
        $tempFile = sys_get_temp_dir().'/audit_export_empty_preview.json';
        $this->removeFileIfExists($tempFile);

        $repository = self::createStub(AuditLogRepositoryInterface::class);
        $repository->method('findAllWithFilters')->willReturn([]);
        $this->repository = $repository;
        $this->resetCommandTester();

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
        $this->removeFileIfExists($tempFile);

        $repository = $this->useRepositoryMock();
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
            ->willReturnCallback(static function (iterable $audits, string $_format, $stream): int {
                $payload = [];
                foreach ($audits as $audit) {
                    $payload[] = $audit->entityId;
                }

                fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));

                return count($payload);
            });

        try {
            $this->commandTester->execute([
                '--output' => $tempFile,
                '--limit' => '2',
            ]);

            self::assertSame(0, $this->commandTester->getStatusCode());
            self::assertSame('["42","43"]', (string) file_get_contents($tempFile));
            self::assertStringContainsString('Exported 2 audit logs', $this->normalizeOutput($this->commandTester));
        } finally {
            $this->removeFileIfExists($tempFile);
        }
    }

    public function testExportToCsv(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'update');

        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$audit]);
        $exporter = $this->useExporterMock();
        $exporter
            ->expects($this->once())
            ->method('exportToStream')
            ->with(self::isIterable(), 'csv', self::isResource())
            ->willReturnCallback(static function (iterable $_audits, string $_format, $stream): int {
                fwrite($stream, "id,entity_class,entity_id,action\n1,App\\Entity\\User,42,update\n");

                return 1;
            });

        ob_start();
        try {
            $this->commandTester->execute([
                '--format' => 'csv',
            ]);
            $streamOutput = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('id,entity_class', $streamOutput);
        self::assertStringContainsString('update', $streamOutput);
        self::assertStringNotContainsString('Found 1 audit logs', $streamOutput);
    }

    public function testExportWithInvalidFormat(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('findAllWithFilters');

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
            ->method('findAllWithFilters');

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
            ->method('findAllWithFilters')
            ->with(self::callback(static function (array $filters) {
                return $filters['entityClass'] === 'User'
                    && $filters['action'] === 'create'
                    && $filters['from'] instanceof DateTimeImmutable
                    && $filters['to'] instanceof DateTimeImmutable;
            }))
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
            ->method('findAllWithFilters')
            ->with([])
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

    private function removeFileIfExists(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        self::assertTrue(unlink($path));
    }

    private function removeDirectoryIfExists(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        self::assertTrue(rmdir($path));
    }

    /**
     * Test that invalid from date fails with proper error.
     */
    public function testExportWithInvalidFromDate(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('findAllWithFilters');

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
            ->method('findAllWithFilters');

        $this->commandTester->execute([
            '--to' => 'invalid-date-format',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Invalid "to" date', $output);
    }
}
