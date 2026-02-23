<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditExportCommand;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class AuditExportCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    private AuditLogRepository&MockObject $repository;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $command = new AuditExportCommand($this->repository, new \Rcsofttech\AuditTrailBundle\Service\AuditExporter());
        $this->commandTester = new CommandTester($command);
    }

    public function testExportWithNoResults(): void
    {
        $this->repository
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

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);

        $this->commandTester->execute([
            '--format' => 'JSON', // Test case-insensitivity
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Found 1 audit logs', $output);
        self::assertStringContainsString('"entity_class"', $output);
        self::assertStringContainsString('User', $output);
    }

    public function testExportToFile(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'create');
        $tempFile = sys_get_temp_dir().'/audit_export_test.json';
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);

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

    public function testExportToCsv(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'App\\Entity\\User', '42', 'update');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);

        $this->commandTester->execute([
            '--format' => 'csv',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('id,entity_class', $output);
        self::assertStringContainsString('update', $output);
    }

    public function testExportWithInvalidFormat(): void
    {
        $this->repository
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
        $this->repository
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
        $this->repository
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
        $this->repository
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
        $this->repository
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
        $this->repository
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
