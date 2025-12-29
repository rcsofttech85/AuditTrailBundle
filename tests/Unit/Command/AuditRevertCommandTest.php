<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditRevertCommand;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Service\AuditReverterInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations()]
class AuditRevertCommandTest extends TestCase
{
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
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setAction('update');

        $this->repository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($log);

        $this->reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, false)
            ->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute(['auditId' => 123]);

        $output = $this->commandTester->getDisplay();
        $normalizedOutput = (string) preg_replace('/\s+/', ' ', $output);
        self::assertStringContainsString('Revert successful', $normalizedOutput);
        self::assertStringContainsString('name: Old Name', $normalizedOutput);
        self::assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertDryRun(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setAction('update');

        $this->repository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($log);

        $this->reverter->expects($this->once())
            ->method('revert')
            ->with($log, true, false)
            ->willReturn(['name' => 'Old Name']);

        $this->commandTester->execute([
            'auditId' => 123,
            '--dry-run' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $normalizedOutput = (string) preg_replace('/\s+/', ' ', $output);
        self::assertStringContainsString('DRY-RUN', $normalizedOutput);
        self::assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertForce(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setAction('create');

        $this->repository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($log);

        $this->reverter->expects($this->once())
            ->method('revert')
            ->with($log, false, true)
            ->willReturn(['action' => 'delete']);

        $this->commandTester->execute([
            'auditId' => 123,
            '--force' => true,
        ]);

        self::assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteAuditNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->commandTester->execute(['auditId' => 999]);

        $output = $this->commandTester->getDisplay();
        $normalizedOutput = (string) preg_replace('/\s+/', ' ', $output);
        self::assertStringContainsString('Audit log with ID 999 not found', $normalizedOutput);
        self::assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteRevertFailure(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setAction('update');

        $this->repository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($log);

        $this->reverter->expects($this->once())
            ->method('revert')
            ->willThrowException(new \RuntimeException('Revert failed'));

        $this->commandTester->execute(['auditId' => 123]);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Revert failed', $output);
        self::assertEquals(1, $this->commandTester->getStatusCode());
    }
}
