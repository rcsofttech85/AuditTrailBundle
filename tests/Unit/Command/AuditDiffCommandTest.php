<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditDiffCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\DiffGeneratorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(AuditDiffCommand::class)]
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

    private function setLogId(AuditLog $log, int $id): void
    {
        $reflection = new \ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, $id);
    }

    private function normalizeOutput(): string
    {
        $output = $this->commandTester->getDisplay();
        // Remove ANSI escape codes if any
        $regex = '/\x1b[[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/';
        $output = (string) preg_replace($regex, '', $output);
        // Remove block decorations (!, [OK], [ERROR], etc.)
        $output = (string) preg_replace('/[!\[\]]+/', ' ', $output);

        // Normalize whitespace
        return (string) preg_replace('/\s+/', ' ', trim($output));
    }

    public function testExecuteWithId(): void
    {
        $log = new AuditLog();
        $this->setLogId($log, 1);
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setUsername('testuser');
        $log->setCreatedAt(new \DateTimeImmutable('2023-01-01 10:00:00'));
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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $output);
        self::assertStringContainsString('Log ID 1', $output);
        self::assertStringContainsString('Action UPDATE', $output);
        self::assertStringContainsString('Date 2023-01-01 10:00:00', $output);
        self::assertStringContainsString('User testuser', $output);
        self::assertStringContainsString('Field', $output);
        self::assertStringContainsString('Old Value', $output);
        self::assertStringContainsString('New Value', $output);
        self::assertStringContainsString('title', $output);
        self::assertStringContainsString('Old Title', $output);
        self::assertStringContainsString('New Title', $output);
    }

    public function testExecuteWithNoSemanticChanges(): void
    {
        $log = new AuditLog();
        $this->setLogId($log, 1);
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setCreatedAt(new \DateTimeImmutable());

        $this->repository->method('find')->willReturn($log);
        $this->diffGenerator->method('generate')->willReturn([]);

        $this->commandTester->execute(['identifier' => '1']);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->normalizeOutput();
        self::assertStringContainsString('No semantic changes found.', $output);
    }

    public function testExecuteWithEntityClassAndId(): void
    {
        $log = new AuditLog();
        $this->setLogId($log, 1);
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setCreatedAt(new \DateTimeImmutable());

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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $output);
        self::assertStringContainsString('Log ID 1', $output);
    }

    public function testExecuteWithEntityShortName(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);

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

    public function testExecuteWithIdAndTimestamps(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction('update'); // Lowercase to test strtoupper
        $log->setCreatedAt(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $this->repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($log);

        $this->diffGenerator->expects($this->once())
            ->method('generate')
            ->with(
                self::anything(),
                self::anything(),
                ['raw' => false, 'include_timestamps' => true]
            )
            ->willReturn(['title' => ['old' => 'Old', 'new' => 'New']]);

        $this->commandTester->execute([
            'identifier' => '1',
            '--include-timestamps' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('UPDATE', $output); // Verify strtoupper
        self::assertStringContainsString('2023-01-01 12:00:00', $output);
    }

    public function testExecuteWithNumericIdentifierAndEntityId(): void
    {
        // If identifier is numeric but entityId is provided, it should treat identifier as class name
        // (which is weird but that's the logic: is_numeric && null === entityId)
        // So if entityId is NOT null, it skips the first block and goes to the second.

        $log = new AuditLog();
        $log->setEntityClass('123'); // Weird class name
        $log->setEntityId('456');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setCreatedAt(new \DateTimeImmutable());

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['entityClass' => '123', 'entityId' => '456'], 1)
            ->willReturn([$log]);

        $this->diffGenerator->method('generate')->willReturn([]);

        $this->commandTester->execute([
            'identifier' => '123',
            'entityId' => '456',
        ]);

        $this->commandTester->assertCommandIsSuccessful();
    }

    public function testExecuteWithRawOption(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setCreatedAt(new \DateTimeImmutable());

        $this->repository->method('find')->willReturn($log);

        $this->diffGenerator->expects($this->once())
            ->method('generate')
            ->with(
                self::anything(),
                self::anything(),
                ['raw' => true, 'include_timestamps' => false]
            )
            ->willReturn([]);

        $this->commandTester->execute([
            'identifier' => '1',
            '--raw' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();
    }

    public function testExecuteNotFound(): void
    {
        $this->repository->method('find')->willReturn(null);

        $this->commandTester->execute(['identifier' => '999']);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', trim($this->commandTester->getDisplay()));
        self::assertStringContainsString('No audit log found with ID 999', (string) $display);
    }

    public function testExecuteEntityIdRequiredForClassIdentifier(): void
    {
        // identifier is NOT numeric (so it's a class), but entityId is null
        $this->commandTester->execute(['identifier' => 'App\Entity\Post']);

        // Should return null (which means success? No, fetchAuditLog returns null, execute returns FAILURE)
        // Wait, fetchAuditLog returns null, execute checks if null === log and returns FAILURE.

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', trim($this->commandTester->getDisplay()));
        self::assertStringContainsString('Entity ID is required', $display);
    }

    public function testExecuteNoLogsFoundForEntity(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([]);

        $this->commandTester->execute([
            'identifier' => 'App\Entity\Post',
            'entityId' => '123',
        ]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithNullAndBoolValues(): void
    {
        $log = new AuditLog();
        $this->setLogId($log, 123);
        $log->setEntityClass('App\Entity\Post');
        $log->setEntityId('123');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setCreatedAt(new \DateTimeImmutable());

        $this->repository->method('find')->willReturn($log);

        $this->diffGenerator->method('generate')->willReturn([
            'is_published' => ['old' => true, 'new' => false],
            'description' => ['old' => null, 'new' => 'Description'],
        ]);

        $this->commandTester->execute(['identifier' => '123'], ['decorated' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->normalizeOutput();

        // Check boolean formatting
        self::assertStringContainsString('TRUE', $output);
        self::assertStringContainsString('FALSE', $output);

        // Check null formatting
        self::assertStringContainsString('NULL', $output);
    }
}
