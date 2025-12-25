<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditPurgeCommand;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditPurgeCommand::class)]
class AuditPurgeCommandTest extends TestCase
{
    private AuditLogRepository&MockObject $repository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $command = new AuditPurgeCommand($this->repository);
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

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('--before', $output);
        $this->assertStringContainsString('required', $output);
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

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid date format', $this->normalizeOutput());
    }

    public function testPurgeWithNoLogsToDelete(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(0);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->execute([
            '--before' => '30 days ago',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No audit logs', $this->normalizeOutput());
    }

    public function testPurgeDryRun(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(100);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->execute([
            '--before' => '30 days ago',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('100', $output);
        $this->assertStringContainsString('Dry run', $output);
    }

    public function testPurgeWithForce(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(50);

        $this->repository
            ->expects($this->once())
            ->method('deleteOldLogs')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(50);

        $this->commandTester->execute([
            '--before' => '30 days ago',
            '--force' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('Successfully deleted', $output);
        $this->assertStringContainsString('50', $output);
    }

    public function testPurgeCancelledByUser(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(50);

        $this->repository
            ->expects($this->never())
            ->method('deleteOldLogs');

        $this->commandTester->setInputs(['no']);

        $this->commandTester->execute([
            '--before' => '30 days ago',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Operation cancelled', $this->normalizeOutput());
    }

    public function testPurgeConfirmedByUser(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(75);

        $this->repository
            ->expects($this->once())
            ->method('deleteOldLogs')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(75);

        $this->commandTester->setInputs(['yes']);

        $this->commandTester->execute([
            '--before' => '60 days ago',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('Successfully deleted', $output);
        $this->assertStringContainsString('75', $output);
    }

    public function testPurgeWithSpecificDateFormat(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('countOlderThan')
            ->with($this->callback(function (\DateTimeInterface $date) {
                return '2024-01-01' === $date->format('Y-m-d');
            }))
            ->willReturn(25);

        $this->repository
            ->expects($this->once())
            ->method('deleteOldLogs')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(25);

        $this->commandTester->execute([
            '--before' => '2024-01-01',
            '--force' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    private function normalizeOutput(): string
    {
        return preg_replace('/\s+/', ' ', $this->commandTester->getDisplay()) ?? '';
    }
}
