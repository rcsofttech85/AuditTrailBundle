<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditExportCommand;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditExportCommand::class)]
#[AllowMockObjectsWithoutExpectations]
class AuditExportCommandTest extends TestCase
{
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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('No audit logs found matching the criteria.', $output);
    }

    public function testExportToJson(): void
    {
        $audit = $this->createAuditLog(1, 'App\\Entity\\User', '42', 'create');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);

        $this->commandTester->execute([
            '--format' => 'JSON', // Test case-insensitivity
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Found 1 audit logs', $output);
        self::assertStringContainsString('"entity_class"', $output);
        self::assertStringContainsString('User', $output);
    }

    public function testExportToFile(): void
    {
        $audit = $this->createAuditLog(1, 'App\\Entity\\User', '42', 'create');
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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Exported 1 audit logs to', $output);
        self::assertFileExists($tempFile);
        self::assertStringContainsString('"entity_class"', (string) file_get_contents($tempFile));

        unlink($tempFile);
    }

    public function testExportToCsv(): void
    {
        $audit = $this->createAuditLog(1, 'App\\Entity\\User', '42', 'update');

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
        self::assertStringContainsString('Invalid format', $this->normalizeOutput());
    }

    public function testExportWithLimitBoundaries(): void
    {
        // Test lower boundary
        $this->commandTester->execute(['--limit' => '0']);
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between 1 and 100000', $this->normalizeOutput());

        // Test upper boundary
        $this->commandTester->execute(['--limit' => '100001']);
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between 1 and 100000', $this->normalizeOutput());
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
        self::assertStringContainsString('Invalid action', $this->normalizeOutput());
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

    private function normalizeOutput(): string
    {
        $output = $this->commandTester->getDisplay();
        $regex = '/\x1b[[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/';
        $output = (string) preg_replace($regex, '', $output);
        $output = (string) preg_replace('/[!\[\]]+/', ' ', $output);

        return (string) preg_replace('/\s+/', ' ', trim($output));
    }

    private function createAuditLog(int $id, string $entityClass, string $entityId, string $action): AuditLog
    {
        $audit = self::createStub(AuditLog::class);

        $audit->method('getId')->willReturn($id);
        $audit->method('getEntityClass')->willReturn($entityClass);
        $audit->method('getEntityId')->willReturn($entityId);
        $audit->method('getAction')->willReturn($action);
        $audit->method('getCreatedAt')->willReturn(new DateTimeImmutable('2024-01-01 12:00:00'));

        return $audit;
    }

    public function testExportNoResultsEarlyReturn(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();

        // Verify warning is shown
        self::assertStringContainsString('No audit logs found matching the criteria.', $output);

        // Verify no export data is shown (the early return prevents further processing)
        self::assertStringNotContainsString('Found', $output);
        self::assertStringNotContainsString('Exported', $output);
        self::assertStringNotContainsString('entity_class', $output);
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
        $output = $this->normalizeOutput();
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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Invalid "to" date', $output);
    }
}
