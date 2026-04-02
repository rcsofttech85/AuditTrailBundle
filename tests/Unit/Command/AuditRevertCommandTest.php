<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditRevertCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

use const JSON_PRETTY_PRINT;
use const PHP_EOL;

final class AuditRevertCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    /** @var (AuditLogRepository&\PHPUnit\Framework\MockObject\Stub)|(AuditLogRepository&MockObject) */
    private AuditLogRepository $repository;

    /** @var (AuditReverterInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditReverterInterface&MockObject) */
    private AuditReverterInterface $reverter;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepository::class);
        $this->reverter = self::createStub(AuditReverterInterface::class);
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

    /** @return AuditReverterInterface&MockObject */
    private function useReverterMock(): AuditReverterInterface
    {
        $reverter = self::createMock(AuditReverterInterface::class);
        $this->reverter = $reverter;
        $this->resetCommandTester();

        return $reverter;
    }

    private function resetCommandTester(): void
    {
        $command = new AuditRevertCommand($this->repository, $this->reverter);
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteRevertSuccess(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $reverter = $this->useReverterMock();
        $reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, false)
            ->willReturn(['name' => 'Old Name', 'age' => 30]);

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $normalizedOutput = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Reverting Audit Log #018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a (update)', $normalizedOutput);
        self::assertStringContainsString('Entity: App\Entity\User:1', $normalizedOutput);
        self::assertStringContainsString('Revert successful', $normalizedOutput);
        self::assertStringContainsString('Changes Applied:', $normalizedOutput);
        self::assertStringContainsString('name: Old Name', $normalizedOutput);
        self::assertStringContainsString('age: 30', $normalizedOutput);
        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertDryRun(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $reverter = $this->useReverterMock();
        $reverter->expects($this->once())
            ->method('revert')
            ->with($log, true, false)
            ->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--dry-run' => true,
        ]);

        $normalizedOutput = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Reverting Audit Log #018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a (update)', $normalizedOutput);
        self::assertStringContainsString('DRY-RUN', $normalizedOutput);
        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertForce(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $reverter = $this->useReverterMock();
        $reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, true)
            ->willReturn(['action' => 'delete']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertNoisy(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->willReturn($log);

        $reverter = $this->useReverterMock();
        $reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, false, [], false)
            ->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--noisy' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteAuditNotFound(): void
    {
        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn(null);

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $normalizedOutput = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Audit log with ID', $normalizedOutput);
        self::assertStringContainsString('not found', $normalizedOutput);
        self::assertSame(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertFailure(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $reverter = $this->useReverterMock();
        $reverter->expects($this->once())
            ->method('revert')
            ->willThrowException(new RuntimeException('Revert failed'));

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $output = (string) preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        self::assertStringContainsString('Revert failed', $output);
        self::assertSame(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteRawOption(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);
        $this->reverter->method('revert')->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--raw' => true,
        ]);

        $expectedJson = json_encode(['name' => 'Old Name'], JSON_PRETTY_PRINT);
        self::assertIsString($expectedJson);
        self::assertSame($expectedJson.PHP_EOL, $this->commandTester->getDisplay());
    }

    public function testExecuteRawOptionSuppressesFormattedDryRunOutput(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);
        $this->reverter->method('revert')->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--raw' => true,
            '--dry-run' => true,
        ]);

        $expectedJson = json_encode(['name' => 'Old Name'], JSON_PRETTY_PRINT);
        self::assertIsString($expectedJson);
        self::assertSame($expectedJson.PHP_EOL, $this->commandTester->getDisplay());
    }

    public function testExecuteRejectsInvalidAuditIdGracefully(): void
    {
        $this->commandTester->execute(['auditId' => 'not-a-uuid']);

        $output = (string) preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('auditId must be a valid UUID', $output);
    }

    public function testExecuteNoChanges(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);
        $this->reverter->method('revert')->willReturn([]);

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $normalizedOutput = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('No changes were applied', $normalizedOutput);
    }

    public function testExecuteNonScalarChange(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);
        $this->reverter->method('revert')->willReturn(['roles' => ['ROLE_USER']]);

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('roles: ["ROLE_USER"]', $output);
    }

    public function testExecuteWithContext(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);

        $context = ['reason' => 'test', 'ticket' => 'T-123'];
        $reverter = $this->useReverterMock();
        $this->repository->method('find')->willReturn($log);
        $reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, false, $context)
            ->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--context' => json_encode($context),
        ]);

        $output = (string) preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Revert successful', $output);
    }

    public function testExecuteWithInvalidContext(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--context' => '{invalid json}',
        ]);

        $output = (string) preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid JSON context', $output);
    }
}
