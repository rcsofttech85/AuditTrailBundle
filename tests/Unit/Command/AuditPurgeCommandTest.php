<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditPurgeCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class AuditPurgeCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    private AuditLogRepository&MockObject $repository;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->integrityService->method('isEnabled')->willReturn(false);
        $command = new AuditPurgeCommand($this->repository, $this->integrityService);
        $this->commandTester = new CommandTester($command);
    }

    public function testPurgeRequiresBeforeOption(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('countOlderThan');

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->execute([]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('--before', $output);
        self::assertStringContainsString('required', $output);
    }

    public function testPurgeWithInvalidDate(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('countOlderThan');

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->execute([
            '--before' => 'not-a-valid-date-format-xyz',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Invalid date format', $output);
        self::assertStringContainsString('Valid formats', $output);
    }

    public function testPurgeWithNoLogsToDelete(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(0);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->execute([
            '--before' => '30 days ago',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('No audit logs found before', $output);

        // Verify early return — no purge summary or confirmation
        self::assertStringNotContainsString('Purge Summary', $output);
        self::assertStringNotContainsString('Are you sure', $output);
        self::assertStringNotContainsString('Successfully', $output);
    }

    public function testPurgeDryRun(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(100);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->execute([
            '--before' => '30 days ago',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Purge Summary', $output);
        self::assertStringContainsString('Setting Value', $output);
        self::assertStringContainsString('Records to delete 100', $output);
        self::assertStringContainsString('Dry run', $output);
    }

    public function testPurgeWithForce(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(50);

        $this->repository
            ->expects($this->once())
            ->method('deleteOldLogs')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(50);

        $this->commandTester->execute([
            '--before' => '30 days ago',
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Deleting audit logs...', $output);
        self::assertStringContainsString('Successfully deleted', $output);
        self::assertStringContainsString('50', $output);
    }

    public function testPurgeCancelledByUser(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(50);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->setInputs(['no']);

        $this->commandTester->execute([
            '--before' => '30 days ago',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Operation cancelled', $this->normalizeOutput($this->commandTester));
    }

    public function testPurgeDefaultCancelled(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->willReturn(50);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        // Empty input should use default (false)
        $this->commandTester->setInputs(["\n"]);

        $this->commandTester->execute([
            '--before' => '30 days ago',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Operation cancelled', $this->normalizeOutput($this->commandTester));
    }

    public function testPurgeConfirmedByUser(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(75);

        $this->repository
            ->expects($this->once())
            ->method('deleteOldLogs')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(75);

        $this->commandTester->setInputs(['yes']);

        $this->commandTester->execute([
            '--before' => '60 days ago',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Successfully deleted', $output);
        self::assertStringContainsString('75', $output);
    }

    public function testPurgeWithSpecificDateFormat(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::callback(static function (DateTimeInterface $date) {
                return '2024-01-01' === $date->format('Y-m-d');
            }))
            ->willReturn(25);

        $this->repository
            ->expects($this->once())
            ->method('deleteOldLogs')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(25);

        $this->commandTester->execute([
            '--before' => '2024-01-01',
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testPurgeWithLargeCountWarning(): void
    {
        // Test boundary 10000
        $this->repository
            ->expects($this->exactly(2))
            ->method('countOlderThan')
            ->willReturnOnConsecutiveCalls(10000, 10001);

        $this->repository
            ->expects($this->exactly(2))
            ->method('deleteOldLogs')
            ->willReturn(10000);

        $this->commandTester->setInputs(['yes']);

        // 10000 should NOT show warning (it's > 10000)
        $this->commandTester->execute(['--before' => '30 days ago']);
        self::assertStringNotContainsString('large operation', $this->normalizeOutput($this->commandTester));

        // 10001 SHOULD show warning
        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['--before' => '30 days ago']);
        self::assertStringContainsString('large operation', $this->normalizeOutput($this->commandTester));
    }
}
