<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\VerifyIntegrityCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class VerifyIntegrityCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    private AuditLogRepository&MockObject $repository;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);

        $command = new VerifyIntegrityCommand($this->repository, $this->integrityService);
        $this->commandTester = new CommandTester($command);
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }

    public function testExecuteIntegrityDisabled(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(false);

        $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('not enabled', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteSingleLogNotFound(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('find')->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')->willReturn(null);

        $this->commandTester->execute(['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('not found', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteSingleLogValid(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'update', new DateTimeImmutable('2024-01-01 12:00:00'));
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('find')->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')->willReturn($log);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(true);

        $this->commandTester->execute(['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Verifying Audit Log #018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', $output);
        self::assertStringContainsString('Entity: App\Entity\User 1', $output);
        self::assertStringContainsString('Action: update', $output);
        self::assertStringContainsString('Created: 2024-01-01 12:00:00', $output);
        self::assertStringContainsString('Signature verification passed', $output);
    }

    public function testExecuteSingleLogInvalid(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'update');
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('find')->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')->willReturn($log);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(false);

        $this->commandTester->execute(['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Signature', $output);
        self::assertStringContainsString('failed', $output);
    }

    public function testExecuteAllLogsNoneFound(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(0);

        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No audit logs found', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteAllLogsValid(): void
    {
        $log1 = new AuditLog('App\Entity\User', '1', 'create');
        $this->setLogId($log1, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $log2 = new AuditLog('App\Entity\User', '2', 'create');
        $this->setLogId($log2, '018f3b3b-3b3b-7b3b-8b3b-3b3b3b3b3b3b');

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(2);

        // Mock pagination: first batch returns logs, second returns empty (loop condition is offset < count)
        $this->repository->expects($this->once())
            ->method('findBy')
            ->with([], ['id' => 'ASC'], 100, 0)
            ->willReturn([$log1, $log2]);

        $this->integrityService->method('verifySignature')->willReturn(true);

        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Verifying Audit Log Integrity', $output);
        self::assertStringContainsString('All 2 audit logs verified', $output);
    }

    public function testExecuteAllLogsTampered(): void
    {
        $log1 = new AuditLog('User', '1', 'update', new DateTimeImmutable('2024-01-01 10:00:00'));
        $this->setLogId($log1, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(1);
        $this->repository->method('findBy')->willReturn([$log1]);

        $this->integrityService->method('verifySignature')->with($log1)->willReturn(false);

        $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Found 1 tampered', $output);
        self::assertStringContainsString('User 1 update 2024-01-01 10:00:00', $output);
    }

    public function testExecuteWithBatching(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(150);

        $batch1 = array_fill(0, 100, new AuditLog('User', '1', 'create'));
        $batch2 = array_fill(0, 50, new AuditLog('User', '1', 'create'));

        $callCount = 0;
        $this->repository->expects($this->exactly(2))
            ->method('findBy')
            ->with(
                [],
                ['id' => 'ASC'],
                100,
                self::callback(static function ($offset) use (&$callCount) {
                    ++$callCount;
                    if ($callCount === 1) {
                        return $offset === 0;
                    }

                    return $offset === 100;
                })
            )
            ->willReturnOnConsecutiveCalls($batch1, $batch2);

        $this->integrityService->method('verifySignature')->willReturn(true);

        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('All 150 audit logs verified', $this->normalizeOutput($this->commandTester));
    }
}
