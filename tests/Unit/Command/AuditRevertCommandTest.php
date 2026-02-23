<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditRevertCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use const JSON_PRETTY_PRINT;

#[AllowMockObjectsWithoutExpectations()]
class AuditRevertCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    private AuditLogRepository&MockObject $repository;

    private AuditReverterInterface&MockObject $reverter;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->reverter = $this->createMock(AuditReverterInterface::class);

        $command = new AuditRevertCommand($this->repository, $this->reverter);
        $application = new Application();
        $application->addCommand($command);
        $command = $application->find('audit:revert');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteRevertSuccess(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $this->reverter->expects($this->once())
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
        self::assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertDryRun(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $this->reverter->expects($this->once())
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
        self::assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertForce(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);

        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $this->reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, true)
            ->willReturn(['action' => 'delete']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--force' => true,
        ]);

        self::assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteAuditNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn(null);

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $normalizedOutput = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Audit log with ID', $normalizedOutput);
        self::assertStringContainsString('not found', $normalizedOutput);
        self::assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertFailure(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);

        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $this->reverter->expects($this->once())
            ->method('revert')
            ->willThrowException(new RuntimeException('Revert failed'));

        $this->commandTester->execute(['auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $output = (string) preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        self::assertStringContainsString('Revert failed', $output);
        self::assertEquals(1, $this->commandTester->getStatusCode());
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

        $output = $this->commandTester->getDisplay();
        // Raw output includes title/text, so it's not pure JSON. Just check for the JSON string.
        $expectedJson = json_encode(['name' => 'Old Name'], JSON_PRETTY_PRINT);
        self::assertIsString($expectedJson);
        self::assertStringContainsString($expectedJson, $output);
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
        $this->reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, false, $context)
            ->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--context' => json_encode($context),
        ]);

        $output = (string) preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        self::assertEquals(0, $this->commandTester->getStatusCode());
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
        self::assertEquals(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid JSON context', $output);
    }
}
