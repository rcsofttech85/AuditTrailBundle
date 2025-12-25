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
        $command = new AuditListCommand($this->repository);
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

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No audit logs', $this->normalizeOutput());
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

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('TestEntity', $output);
        $this->assertStringContainsString('42', $output);
        $this->assertStringContainsString('update', $output);
        // Default view shows tip but NOT the details column
        $this->assertStringContainsString('--details', $output);
        $this->assertStringNotContainsString('Old Title', $output);
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

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        // Detailed view shows Entity ID but not Entity name or ID columns
        $this->assertStringContainsString('42', $output);
        $this->assertStringContainsString('Old Title', $output);
        $this->assertStringContainsString('New Title', $output);
        $this->assertStringNotContainsString('TestEntity', $output);
    }

    public function testListWithFilters(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                $this->callback(function (array $filters) {
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

        $this->assertSame(0, $this->commandTester->getStatusCode());
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

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithDateFilters(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                $this->callback(function (array $filters) {
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

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testListWithInvalidAction(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--action' => 'invalid_action',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid action', $this->normalizeOutput());
    }

    public function testListWithInvalidLimit(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--limit' => '9999',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Limit must be between', $this->normalizeOutput());
    }

    public function testListWithInvalidFromDate(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--from' => 'not-a-date',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('Invalid', $output);
        $this->assertStringContainsString('from', $output);
    }

    public function testListWithInvalidToDate(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--to' => 'invalid-date',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $output = $this->normalizeOutput();
        $this->assertStringContainsString('Invalid', $output);
        $this->assertStringContainsString('to', $output);
    }

    private function normalizeOutput(): string
    {
        return preg_replace('/\s+/', ' ', $this->commandTester->getDisplay()) ?? '';
    }

    private function createAuditLog(int $id, string $entityClass, string $entityId, string $action): AuditLog
    {
        $audit = $this->createStub(AuditLog::class);

        $audit->method('getId')->willReturn($id);
        $audit->method('getEntityClass')->willReturn($entityClass);
        $audit->method('getEntityId')->willReturn($entityId);
        $audit->method('getAction')->willReturn($action);
        $audit->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $audit->method('getUsername')->willReturn('test_user');
        $audit->method('getChangedFields')->willReturn(['title']);
        $audit->method('getOldValues')->willReturn(['title' => 'Old Title']);
        $audit->method('getNewValues')->willReturn(['title' => 'New Title']);

        return $audit;
    }
}
