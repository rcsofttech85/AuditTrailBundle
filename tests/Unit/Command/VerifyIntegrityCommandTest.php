<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\VerifyIntegrityCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(VerifyIntegrityCommand::class)]
class VerifyIntegrityCommandTest extends TestCase
{
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

    private function setLogId(AuditLog $log, int $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, $id);
    }

    private function normalizeOutput(): string
    {
        $output = $this->commandTester->getDisplay();
        $regex = '/\x1b[[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/';
        $output = (string) preg_replace($regex, '', $output);
        $output = (string) preg_replace('/[!\[\]]+/', ' ', $output);

        return (string) preg_replace('/\s+/', ' ', trim($output));
    }

    public function testExecuteIntegrityDisabled(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(false);

        $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('not enabled', $this->normalizeOutput());
    }

    public function testExecuteSingleLogNotFound(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('find')->with(1)->willReturn(null);

        $this->commandTester->execute(['--id' => 1]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('not found', $this->normalizeOutput());
    }

    public function testExecuteSingleLogValid(): void
    {
        $log = new AuditLog();
        $this->setLogId($log, 1);
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setAction('update');
        $log->setCreatedAt(new DateTimeImmutable('2024-01-01 12:00:00'));

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('find')->with(1)->willReturn($log);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(true);

        $this->commandTester->execute(['--id' => 1]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Verifying Audit Log #1', $output);
        self::assertStringContainsString('Entity: App\Entity\User 1', $output);
        self::assertStringContainsString('Action: update', $output);
        self::assertStringContainsString('Created: 2024-01-01 12:00:00', $output);
        self::assertStringContainsString('Signature verification passed', $output);
    }

    public function testExecuteSingleLogInvalid(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setAction('update');
        $log->setCreatedAt(new DateTimeImmutable());

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('find')->with(1)->willReturn($log);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(false);

        $this->commandTester->execute(['--id' => 1]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Signature', $output);
        self::assertStringContainsString('failed', $output);
    }

    public function testExecuteAllLogsNoneFound(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(0);

        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No audit logs found', $this->normalizeOutput());
    }

    public function testExecuteAllLogsValid(): void
    {
        $log1 = new AuditLog();
        $this->setLogId($log1, 1);
        $log2 = new AuditLog();
        $this->setLogId($log2, 2);

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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Verifying Audit Log Integrity', $output);
        self::assertStringContainsString('All 2 audit logs verified', $output);
    }

    public function testExecuteAllLogsTampered(): void
    {
        $log1 = new AuditLog();
        $this->setLogId($log1, 1);
        $log1->setEntityClass('User');
        $log1->setEntityId('1');
        $log1->setAction('update');
        $log1->setCreatedAt(new DateTimeImmutable('2024-01-01 10:00:00'));

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(1);
        $this->repository->method('findBy')->willReturn([$log1]);

        $this->integrityService->method('verifySignature')->with($log1)->willReturn(false);

        $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Found 1 tampered', $output);
        self::assertStringContainsString('1 User 1 update 2024-01-01 10:00:00', $output);
    }

    public function testExecuteWithBatching(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(150);

        $batch1 = array_fill(0, 100, new AuditLog());
        $batch2 = array_fill(0, 50, new AuditLog());

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
        self::assertStringContainsString('All 150 audit logs verified', $this->normalizeOutput());
    }
}
