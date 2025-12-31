<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditListCommand;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditListCommand::class)]
class AuditListCommandTest extends TestCase
{
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
        self::assertStringContainsString('No audit logs', $this->normalizeOutput());
    }

    public function testListWithResults(): void
    {
        $audit = $this->createAuditLog(1, 'TestEntity', '42', 'update');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([$audit]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        self::assertStringContainsString('TestEntity', $output);
        self::assertStringContainsString('42', $output);
        self::assertStringContainsString('update', $output);
        // Default view shows tip but NOT the details column
        self::assertStringContainsString('--details', $output);
        self::assertStringNotContainsString('Old Title', $output);
    }

    public function testListWithDetailsFlag(): void
    {
        $audit = $this->createAuditLog(1, 'TestEntity', '42', 'update');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with([], 50)
            ->willReturn([$audit]);

        $this->commandTester->execute(['--details' => true]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
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
                self::callback(function (array $filters) {
                    return 'TestEntity' === $filters['entityClass']
                        && 'update' === $filters['action'];
                }),
                50
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--entity' => 'TestEntity',
            '--action' => 'update',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithTransactionFilter(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'abc-123' === $filters['transactionHash'];
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

    public function testListWithDateFilters(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return isset($filters['from'])
                        && $filters['from'] instanceof \DateTimeInterface
                        && isset($filters['to'])
                        && $filters['to'] instanceof \DateTimeInterface;
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
        self::assertStringContainsString('Invalid action', $this->normalizeOutput());
    }

    public function testListWithInvalidLimit(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--limit' => '9999',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between', $this->normalizeOutput());
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
        $output = $this->normalizeOutput();
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
        $output = $this->normalizeOutput();
        self::assertStringContainsString('Invalid', $output);
        self::assertStringContainsString('to', $output);
    }

    private function normalizeOutput(): string
    {
        return preg_replace('/\s+/', ' ', $this->commandTester->getDisplay()) ?? '';
    }

    private function createAuditLog(int $id, string $entityClass, string $entityId, string $action): AuditLog
    {
        $audit = self::createStub(AuditLog::class);

        $audit->method('getId')->willReturn($id);
        $audit->method('getEntityClass')->willReturn($entityClass);
        $audit->method('getEntityId')->willReturn($entityId);
        $audit->method('getAction')->willReturn($action);
        $audit->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $audit->method('getUsername')->willReturn('test_user');
        $audit->method('getChangedFields')->willReturn(['title']);
        $audit->method('getOldValues')->willReturn(['title' => 'Old Title']);
        $audit->method('getNewValues')->willReturn(['title' => 'New Title']);
        $audit->method('getTransactionHash')->willReturn('abc-123-def-456');

        return $audit;
    }
}
