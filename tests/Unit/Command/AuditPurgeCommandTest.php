<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditPurgeCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Console\Tester\CommandTester;

final class AuditPurgeCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    private AuditLogRepositoryInterface $repository;

    /** @var (AuditIntegrityServiceInterface&Stub)|(AuditIntegrityServiceInterface&MockObject) */
    private AuditIntegrityServiceInterface $integrityService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->integrityService->method('isEnabled')->willReturn(false);
        $this->resetCommandTester();
    }

    public function testPurgeRequiresBeforeOption(): void
    {
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('countOlderThan');

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->never())
            ->method('countOlderThan');

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(0);

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(100);

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(50);

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(50);

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->willReturn(50);

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::isInstanceOf(DateTimeInterface::class))
            ->willReturn(75);

        $repository
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
        $repository = $this->useRepositoryMock();
        $repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with(self::callback(static function (DateTimeInterface $date) {
                return '2024-01-01' === $date->format('Y-m-d');
            }))
            ->willReturn(25);

        $repository
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
        $repository = $this->useRepositoryMock();
        // Test boundary 10000
        $repository
            ->expects($this->exactly(2))
            ->method('countOlderThan')
            ->willReturnOnConsecutiveCalls(10000, 10001);

        $repository
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

    public function testPurgeIntegrityCheckStreamsOlderLogsThroughIterableQuery(): void
    {
        $repository = $this->useRepositoryMock();
        $this->integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->expects($this->once())
            ->method('verifySignature')
            ->with(self::isInstanceOf(AuditLog::class))
            ->willReturn(true);
        $this->resetCommandTester();

        $repository->expects($this->once())
            ->method('countOlderThan')
            ->willReturn(1);
        $repository->expects($this->once())
            ->method('findAllWithFilters')
            ->with(self::callback(static function (array $filters): bool {
                return isset($filters['to']) && $filters['to'] instanceof DateTimeImmutable;
            }))
            ->willReturn([new AuditLog('Class', '1', 'update')]);
        $repository->expects($this->once())
            ->method('deleteOldLogs')
            ->willReturn(1);

        $this->commandTester->execute([
            '--before' => '30 days ago',
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('All logs passed integrity verification', $this->normalizeOutput($this->commandTester));
    }

    private function useRepositoryMock(): AuditLogRepositoryInterface&MockObject
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->repository = $repository;
        $this->resetCommandTester();

        return $repository;
    }

    private function resetCommandTester(): void
    {
        $command = new AuditPurgeCommand($this->repository, $this->integrityService);
        $this->commandTester = new CommandTester($command);
    }
}
