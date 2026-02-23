<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditListCommand;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class AuditListCommandTest extends TestCase
{
    use ConsoleOutputTestTrait;

    private AuditLogRepository&MockObject $repository;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $command = new AuditListCommand($this->repository, new \Rcsofttech\AuditTrailBundle\Service\AuditRenderer());
        $this->commandTester = new CommandTester($command);
    }

    public function testListWithNoResults(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('No audit logs found matching the criteria.', $output);

        // Verify early return — no table is rendered
        self::assertStringNotContainsString('Audit Logs', $output);
        self::assertStringNotContainsString('Entity', $output);
        self::assertStringNotContainsString('Action', $output);
        self::assertStringNotContainsString('--details', $output);
    }

    public function testListWithResults(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'TestEntity', '42', 'update');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([$audit]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Audit Logs (1 results)', $output);
        self::assertStringContainsString('TestEntity', $output);
        self::assertStringContainsString('42', $output);
        self::assertStringContainsString('update', $output);
        // Default view shows tip but NOT the details column
        self::assertStringContainsString('--details', $output);
        self::assertStringNotContainsString('Old Title', $output);
    }

    public function testListWithDetailsFlag(): void
    {
        $audit = $this->createAuditLog('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', 'TestEntity', '42', 'update');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([$audit]);

        $this->commandTester->execute(['--details' => true]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        // Detailed view shows Entity ID but not Entity name or ID columns
        self::assertStringContainsString('42', $output);
        self::assertStringContainsString('Old Title', $output);
        self::assertStringContainsString('New Title', $output);
        self::assertStringNotContainsString('TestEntity', $output);
    }

    public function testListWithFilters(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return $filters['entityClass'] === 'TestEntity'
                        && $filters['action'] === 'update'
                        && $filters['userId'] === '123';
                }),
                50
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--entity' => 'TestEntity',
            '--action' => 'update',
            '--user' => '123',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithEmptyFilters(): void
    {
        // Empty strings should be ignored
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([]);

        $this->commandTester->execute([
            '--entity' => '',
            '--action' => '',
            '--user' => '',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithTransactionFilter(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return $filters['transactionHash'] === 'abc-123';
                }),
                50
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--transaction' => 'abc-123',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithCustomLimit(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 100)
            ->willReturn([]);

        $this->commandTester->execute([
            '--limit' => '100',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithDefaultLimit(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([]);

        // Passing non-numeric limit should fallback to 50
        $this->commandTester->execute([
            '--limit' => 'abc',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithDateFilters(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return isset($filters['from'])
                        && $filters['from'] instanceof DateTimeInterface
                        && isset($filters['to'])
                        && $filters['to'] instanceof DateTimeInterface;
                }),
                50
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithInvalidAction(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--action' => 'invalid_action',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid action', $this->normalizeOutput($this->commandTester));
    }

    public function testListWithLimitBoundaries(): void
    {
        // Test lower boundary
        $this->commandTester->execute(['--limit' => '0']);
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between 1 and 1000', $this->normalizeOutput($this->commandTester));

        // Test upper boundary
        $this->commandTester->execute(['--limit' => '1001']);
        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between 1 and 1000', $this->normalizeOutput($this->commandTester));
    }

    public function testListWithInvalidFromDate(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--from' => 'not-a-date',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Invalid', $output);
        self::assertStringContainsString('from', $output);
    }

    public function testListWithInvalidToDate(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--to' => 'invalid-date',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput($this->commandTester);
        self::assertStringContainsString('Invalid', $output);
        self::assertStringContainsString('to', $output);
    }

    private function createAuditLog(string $id, string $entityClass, string $entityId, string $action): AuditLog
    {
        $log = new AuditLog(
            $entityClass,
            $entityId,
            $action,
            new DateTimeImmutable('2024-01-01 12:00:00'),
            oldValues: ['title' => 'Old Title'],
            newValues: ['title' => 'New Title'],
            changedFields: ['title'],
            transactionHash: 'abc-123-def-456',
            username: 'test_user'
        );

        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));

        return $log;
    }
}
