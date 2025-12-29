<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Command\AuditExportCommand;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditExportCommand::class)]
class AuditExportCommandTest extends TestCase
{
    private AuditLogRepository&MockObject $repository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $command = new AuditExportCommand($this->repository);
        $this->commandTester = new CommandTester($command);
    }

    public function testExportWithNoResults(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No audit logs', $this->normalizeOutput());
    }

    public function testExportToJson(): void
    {
        $audit = $this->createAuditLog(1, 'App\\Entity\\User', '42', 'create');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);

        $this->commandTester->execute([
            '--format' => 'json',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('"entity_class"', $output);
        self::assertStringContainsString('User', $output);
    }

    public function testExportToCsv(): void
    {
        $audit = $this->createAuditLog(1, 'App\\Entity\\User', '42', 'update');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$audit]);

        $this->commandTester->execute([
            '--format' => 'csv',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('id,entity_class', $output);
        self::assertStringContainsString('update', $output);
    }

    public function testExportWithInvalidFormat(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--format' => 'xml',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid format', $this->normalizeOutput());
    }

    public function testExportWithInvalidLimit(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('findWithFilters');

        $this->commandTester->execute([
            '--limit' => '999999',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Limit must be between', $this->normalizeOutput());
    }

    public function testExportWithInvalidAction(): void
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

    public function testExportWithFilters(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'User' === $filters['entityClass']
                        && 'create' === $filters['action'];
                }),
                1000
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--entity' => 'User',
            '--action' => 'create',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
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

        return $audit;
    }
}
