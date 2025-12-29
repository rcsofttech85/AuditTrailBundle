<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditDiffCommand;
use Rcsofttech\AuditTrailBundle\Contract\DiffGeneratorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class AuditDiffCommandTest extends TestCase
{
    private AuditLogRepository&MockObject $repository;
    private DiffGeneratorInterface&MockObject $diffGenerator;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->diffGenerator = $this->createMock(DiffGeneratorInterface::class);

        $command = new AuditDiffCommand($this->repository, $this->diffGenerator);
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteWithId(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLog::ACTION_UPDATE);
        $log->setOldValues(['title' => 'Old Title']);
        $log->setNewValues(['title' => 'New Title']);

        $this->repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($log);

        $this->diffGenerator->expects($this->once())
            ->method('generate')
            ->willReturn(['title' => ['old' => 'Old Title', 'new' => 'New Title']]);

        $this->commandTester->execute(['identifier' => '1']);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $output);
        self::assertStringContainsString('title', $output);
        self::assertStringContainsString('Old Title', $output);
        self::assertStringContainsString('New Title', $output);
    }

    public function testExecuteWithEntityClassAndId(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLog::ACTION_UPDATE);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['entityClass' => 'App\Entity\Post', 'entityId' => '123'], 1)
            ->willReturn([$log]);

        $this->diffGenerator->expects($this->once())
            ->method('generate')
            ->willReturn(['title' => ['old' => 'Old Title', 'new' => 'New Title']]);

        $this->commandTester->execute([
            'identifier' => 'App\Entity\Post',
            'entityId' => '123',
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $this->commandTester->getDisplay());
    }

    public function testExecuteWithEntityShortName(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLog::ACTION_UPDATE);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['entityClass' => 'Post', 'entityId' => '123'], 1)
            ->willReturn([$log]);

        $this->diffGenerator->expects($this->once())
            ->method('generate')
            ->willReturn(['title' => ['old' => 'Old Title', 'new' => 'New Title']]);

        $this->commandTester->execute([
            'identifier' => 'Post',
            'entityId' => '123',
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $this->commandTester->getDisplay());
    }

    public function testExecuteWithJsonOption(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');

        $this->repository->method('find')->willReturn($log);
        $this->diffGenerator->method('generate')->willReturn(['title' => ['old' => 'A', 'new' => 'B']]);

        $this->commandTester->execute([
            'identifier' => '1',
            '--json' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        self::assertJson($output);
        self::assertStringContainsString('"old": "A"', $output);
    }

    public function testExecuteNotFound(): void
    {
        $this->repository->method('find')->willReturn(null);

        $this->commandTester->execute(['identifier' => '999']);

        self::assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No audit log', $output);
        self::assertStringContainsString('found.', $output);
    }
}
