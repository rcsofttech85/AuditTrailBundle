<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditDiffCommand;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\DiffGeneratorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use ReflectionClass;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class AuditDiffCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    /** @var (AuditLogRepositoryInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditLogRepositoryInterface&MockObject) */
    private AuditLogRepositoryInterface $repository;

    /** @var (DiffGeneratorInterface&\PHPUnit\Framework\MockObject\Stub)|(DiffGeneratorInterface&MockObject) */
    private DiffGeneratorInterface $diffGenerator;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->diffGenerator = self::createStub(DiffGeneratorInterface::class);
        $this->resetCommandTester();
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }

    /** @return AuditLogRepositoryInterface&MockObject */
    private function useRepositoryMock(): AuditLogRepositoryInterface
    {
        $repository = self::createMock(AuditLogRepositoryInterface::class);
        $this->repository = $repository;
        $this->resetCommandTester();

        return $repository;
    }

    /** @return DiffGeneratorInterface&MockObject */
    private function useDiffGeneratorMock(): DiffGeneratorInterface
    {
        $diffGenerator = self::createMock(DiffGeneratorInterface::class);
        $this->diffGenerator = $diffGenerator;
        $this->resetCommandTester();

        return $diffGenerator;
    }

    private function resetCommandTester(): void
    {
        $this->commandTester = new CommandTester(new AuditDiffCommand($this->repository, $this->diffGenerator));
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

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $diffGenerator = $this->useDiffGeneratorMock();
        $diffGenerator->expects($this->once())
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

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['entityClass' => 'App\Entity\Post', 'entityId' => '123'], 1)
            ->willReturn([$log]);

        $diffGenerator = $this->useDiffGeneratorMock();
        $diffGenerator->expects($this->once())
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

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['entityClass' => 'Post', 'entityId' => '123'], 1)
            ->willReturn([$log]);

        $diffGenerator = $this->useDiffGeneratorMock();
        $diffGenerator->expects($this->once())
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

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('find')
            ->with('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a')
            ->willReturn($log);

        $diffGenerator = $this->useDiffGeneratorMock();
        $diffGenerator->expects($this->once())
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

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
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

        $diffGenerator = $this->useDiffGeneratorMock();
        $diffGenerator->expects($this->once())
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

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', trim($this->commandTester->getDisplay()));
        self::assertStringContainsString('Entity ID is required', $display);
    }

    public function testExecuteNoLogsFoundForEntity(): void
    {
        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([]);

        $this->commandTester->execute([
            'identifier' => 'App\Entity\Post',
            'entityId' => '123',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', trim($this->commandTester->getDisplay()));
        self::assertStringContainsString('No audit logs found for App\Entity\Post:123.', $display);
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

    public function testExecuteFormatsDateObjectsFallbackObjectsAndInvalidUtf8Arrays(): void
    {
        $log = new AuditLog('App\\Entity\\Post', '1', AuditLogInterface::ACTION_UPDATE);
        $this->setLogId($log, '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a');

        $this->repository->method('find')->willReturn($log);
        $this->diffGenerator->method('generate')->willReturn([
            'publishedAt' => ['old' => new DateTimeImmutable('2024-03-12 15:30:00'), 'new' => null],
            'payload' => ['old' => ['bad' => "\xB1\x31"], 'new' => []],
            'objectValue' => ['old' => new stdClass(), 'new' => 'done'],
        ]);

        $this->commandTester->execute(['identifier' => '018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a']);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('2024-03-12 15:30:00', $output);
        self::assertStringContainsString('unencodable data', $output);
        self::assertStringContainsString('stdClass', $output);
    }

    public function testExecuteFailsWhenEntityIdIsMissingForEntityClassInput(): void
    {
        $repository = $this->useRepositoryMock();
        $repository->expects($this->never())->method('find');
        $repository->expects($this->never())->method('findWithFilters');

        $this->commandTester->execute([
            'identifier' => 'App\\Entity\\Post',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString(
            'Entity ID is required when providing an Entity Class.',
            $this->normalizeOutput($this->commandTester)
        );
    }
}
