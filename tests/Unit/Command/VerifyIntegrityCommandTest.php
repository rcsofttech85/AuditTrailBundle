<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\VerifyIntegrityCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class VerifyIntegrityCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    /** @var (AuditLogRepository&\PHPUnit\Framework\MockObject\Stub)|(AuditLogRepository&MockObject) */
    private AuditLogRepository $repository;

    /** @var (AuditIntegrityServiceInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditIntegrityServiceInterface&MockObject) */
    private AuditIntegrityServiceInterface $integrityService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepository::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->resetCommandTester();
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }

    /** @return AuditLogRepository&MockObject */
    private function useRepositoryMock(): AuditLogRepository
    {
        $repository = self::createMock(AuditLogRepository::class);
        $this->repository = $repository;
        $this->resetCommandTester();

        return $repository;
    }

    /** @return AuditIntegrityServiceInterface&MockObject */
    private function useIntegrityServiceMock(): AuditIntegrityServiceInterface
    {
        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $this->integrityService = $integrityService;
        $this->resetCommandTester();

        return $integrityService;
    }

    private function resetCommandTester(): void
    {
        $this->commandTester = new CommandTester(new VerifyIntegrityCommand($this->repository, $this->integrityService));
    }

    public function testExecuteIntegrityDisabled(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(false);

        $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('not enabled', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteSingleLogNotFound(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn(null);

        $this->commandTester->execute(['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('not found', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteSingleLogRejectsInvalidUuid(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $repository = $this->useRepositoryMock();
        $repository->expects($this->never())->method('find');
        $repository->expects($this->never())->method('count');

        $this->commandTester->execute(['--id' => 'not-a-uuid']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('valid UUID', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteSingleLogValid(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'update', new DateTimeImmutable('2024-01-01 12:00:00'));
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $repository = $this->useRepositoryMock();
        $integrityService = $this->useIntegrityServiceMock();
        $integrityService->method('isEnabled')->willReturn(true);
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);
        $integrityService->expects($this->once())
            ->method('verifySignature')
            ->with($log)
            ->willReturn(true);

        $this->commandTester->execute(['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
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
        $repository = $this->useRepositoryMock();
        $integrityService = $this->useIntegrityServiceMock();
        $integrityService->method('isEnabled')->willReturn(true);
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);
        $integrityService->expects($this->once())
            ->method('verifySignature')
            ->with($log)
            ->willReturn(false);

        $this->commandTester->execute(['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Signature', $output);
        self::assertStringContainsString('failed', $output);
    }

    public function testExecuteSingleLogInvalidShowsVerboseDebugInformation(): void
    {
        $log = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            oldValues: ['bad' => "\xB1\x31"],
            newValues: ['ok' => 'value']
        );
        $log->signature = 'stored-signature';
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');

        $repository = $this->useRepositoryMock();
        $integrityService = $this->useIntegrityServiceMock();
        $integrityService->method('isEnabled')->willReturn(true);
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);
        $integrityService->expects($this->once())
            ->method('verifySignature')
            ->with($log)
            ->willReturn(false);
        $integrityService->expects($this->once())
            ->method('generateSignature')
            ->with($log)
            ->willReturn('expected-signature');

        $this->commandTester->execute(
            ['--id' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a'],
            ['verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE]
        );

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Debug Information', $output);
        self::assertStringContainsString('Expected Signature:', $output);
        self::assertStringContainsString('expected-signature', $output);
        self::assertStringContainsString('Actual Signature:', $output);
        self::assertStringContainsString('stored-signature', $output);
        self::assertStringContainsString('unencodable data', $output);
        self::assertStringContainsString('{"ok":"value"}', $output);
    }

    public function testExecuteAllLogsNoneFound(): void
    {
        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->repository->method('count')->willReturn(0);

        $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No audit logs found', $this->normalizeOutput($this->commandTester));
    }

    public function testExecuteAllLogsValid(): void
    {
        $log1 = new AuditLog('App\Entity\User', '1', 'create');
        $this->setLogId($log1, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $log2 = new AuditLog('App\Entity\User', '2', 'create');
        $this->setLogId($log2, '018f3b3b-3b3b-7b3b-8b3b-3b3b3b3b3b3b');

        $repository = $this->useRepositoryMock();
        $this->integrityService->method('isEnabled')->willReturn(true);
        $repository->method('count')->willReturn(2);
        $repository->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn([$log1, $log2]);

        $this->integrityService->method('verifySignature')->willReturn(true);

        $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Verifying Audit Log Integrity', $output);
        self::assertStringContainsString('All 2 audit logs verified', $output);
    }

    public function testExecuteAllLogsTampered(): void
    {
        $log1 = new AuditLog('User', '1', 'update', new DateTimeImmutable('2024-01-01 10:00:00'));
        $this->setLogId($log1, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');
        $this->repository->method('count')->willReturn(1);
        $this->repository->method('findAllWithFilters')->willReturn([$log1]);
        $integrityService = $this->useIntegrityServiceMock();
        $integrityService->method('isEnabled')->willReturn(true);

        $integrityService->expects($this->once())
            ->method('verifySignature')
            ->with($log1)
            ->willReturn(false);

        $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Found 1 tampered', $output);
        self::assertStringContainsString('User 1 update 2024-01-01 10:00:00', $output);
    }

    public function testExecuteWithBatching(): void
    {
        $repository = $this->useRepositoryMock();
        $this->integrityService->method('isEnabled')->willReturn(true);
        $repository->method('count')->willReturn(150);

        $batch1 = array_fill(0, 100, new AuditLog('User', '1', 'create'));
        $batch2 = array_fill(0, 50, new AuditLog('User', '1', 'create'));

        $repository->expects($this->once())
            ->method('findAllWithFilters')
            ->with([])
            ->willReturn((static function () use ($batch1, $batch2): iterable {
                yield from $batch1;
                yield from $batch2;
            })());

        $this->integrityService->method('verifySignature')->willReturn(true);

        $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('All 150 audit logs verified', $this->normalizeOutput($this->commandTester));
    }
}
