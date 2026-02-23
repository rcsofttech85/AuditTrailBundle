<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditDiffCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\DiffGeneratorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class AuditDiffCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

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

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }

    public function testExecuteWithId(): void
    {
        $log = new AuditLog(
            'App\Entity\Post',
            '123',
            AuditLogInterface::ACTION_UPDATE,
            new DateTimeImmutable('2023-01-01 10:00:00'),
            oldValues: ['title' => 'Old Title'],
            newValues: ['title' => 'New Title'],
            username: 'testuser'
        );
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');

        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $this->diffGenerator->expects($this->once())
            ->method('generate')
            ->willReturn(['title' => ['old' => 'Old Title', 'new' => 'New Title']]);

        $this->commandTester->execute(['identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $output);
        self::assertStringContainsString('Log ID 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', $output);
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
        $log = new AuditLog('App\Entity\Post', '123', AuditLogInterface::ACTION_UPDATE);
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');

        $this->repository->method('find')->willReturn($log);
        $this->diffGenerator->method('generate')->willReturn([]);

        $this->commandTester->execute(['identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('No semantic changes found.', $output);

        // Verify early return — table is NOT rendered
        self::assertStringNotContainsString('Old Value', $output);
        self::assertStringNotContainsString('New Value', $output);
    }

    public function testExecuteWithEntityClassAndId(): void
    {
        $log = new AuditLog('App\Entity\Post', '123', AuditLogInterface::ACTION_UPDATE);
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');

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
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Audit Diff for App\Entity\Post #123', $output);
        self::assertStringContainsString('Log ID 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', $output);
    }

    public function testExecuteWithEntityShortName(): void
    {
        $log = new AuditLog('App\Entity\Post', '123', AuditLogInterface::ACTION_UPDATE);

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
        $log = new AuditLog('App\Entity\Post', '123', AuditLogInterface::ACTION_UPDATE);

        $this->repository->method('find')->willReturn($log);
        $this->diffGenerator->method('generate')->willReturn(['title' => ['old' => 'A', 'new' => 'B']]);

        $this->commandTester->execute([
            'identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--json' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        self::assertJson($output);
        self::assertStringContainsString('"old": "A"', $output);
    }

    public function testExecuteWithIdAndTimestamps(): void
    {
        $log = new AuditLog(
            'App\Entity\Post',
            '123',
            AuditLogInterface::ACTION_UPDATE,
            new DateTimeImmutable('2023-01-01 12:00:00')
        );

        $this->repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
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
            'identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--include-timestamps' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('UPDATE', $output); // Verify strtoupper
        self::assertStringContainsString('2023-01-01 12:00:00', $output);
    }

    public function testExecuteWithNumericIdentifierAndEntityId(): void
    {
        $log = new AuditLog('123', '456', AuditLogInterface::ACTION_UPDATE);

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
        $log = new AuditLog('App\Entity\Post', '123', AuditLogInterface::ACTION_UPDATE);

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
            'identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a',
            '--raw' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();
    }

    public function testExecuteNotFound(): void
    {
        $this->repository->method('find')->willReturn(null);

        $this->commandTester->execute(['identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', trim($this->commandTester->getDisplay()));
        self::assertStringContainsString('Audit log with ID', (string) $display);
        self::assertStringContainsString('not found.', (string) $display);
    }

    public function testExecuteEntityIdRequiredForClassIdentifier(): void
    {
        // identifier is NOT numeric (so it's a class), but entityId is null
        $this->commandTester->execute(['identifier' => 'App\Entity\Post']);

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
        $log = new AuditLog('App\Entity\Post', '123', AuditLogInterface::ACTION_UPDATE);
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a362731');

        $this->repository->method('find')->willReturn($log);

        $this->diffGenerator->method('generate')->willReturn([
            'is_published' => ['old' => true, 'new' => false],
            'description' => ['old' => null, 'new' => 'Description'],
        ]);

        $this->commandTester->execute(['identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a362731'], ['decorated' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->normalizeOutput($this->commandTester);

        // Check boolean formatting
        self::assertStringContainsString('TRUE', $output);
        self::assertStringContainsString('FALSE', $output);

        // Check null formatting
        self::assertStringContainsString('NULL', $output);
    }

    public function testFormatValueBooleanTernaryOrder(): void
    {
        $log = new AuditLog('App\\Entity\\Post', '1', AuditLogInterface::ACTION_UPDATE);
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');

        $this->repository->method('find')->willReturn($log);

        // old=true, new=false - the table should show TRUE in old column, FALSE in new column
        $this->diffGenerator->method('generate')->willReturn([
            'active' => ['old' => true, 'new' => false],
        ]);

        $this->commandTester->execute(['identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $this->commandTester->assertCommandIsSuccessful();
        $display = $this->commandTester->getDisplay();

        $truePos = strpos($display, 'TRUE');
        $falsePos = strpos($display, 'FALSE');

        self::assertNotFalse($truePos, 'TRUE should be in output');
        self::assertNotFalse($falsePos, 'FALSE should be in output');
        self::assertLessThan($falsePos, $truePos, 'TRUE (old value) should appear before FALSE (new value)');
    }
}
