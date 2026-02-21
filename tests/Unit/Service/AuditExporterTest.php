<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditExporter;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class AuditExporterTest extends TestCase
{
    private AuditExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new AuditExporter();
    }

    public function testSanitizeCsvValue(): void
    {
        $log = new AuditLog(
            entityClass: 'User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2024-01-01T12:00:00+00:00'),
            oldValues: ['name' => '=1+1'],
            newValues: ['name' => '+SUM(A1)'],
            changedFields: ['name'],
            userId: 'admin',
            username: '-malicious',
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla'
        );

        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($log, Uuid::v4());

        $audits = [$log];

        $csv = $this->exporter->formatAsCsv($audits);

        // Header + 1 row
        $lines = explode("\n", trim($csv));
        self::assertCount(2, $lines);

        $dataRow = $lines[1];

        // Ensure string values are prefixed with '
        self::assertStringContainsString("'-malicious", $dataRow);
        self::assertStringContainsString('127.0.0.1', $dataRow);

        // JSON values should be preserved but the trigger character is inside the JSON string
        self::assertStringContainsString('"=1+1"', $dataRow);
        self::assertStringContainsString('"+SUM(A1)"', $dataRow);
    }

    public function testFormatAuditsJson(): void
    {
        $log = new AuditLog('User', '1', 'create', new DateTimeImmutable('2024-01-01 12:00:00'));
        $json = $this->exporter->formatAudits([$log], 'json');

        self::assertStringContainsString('"entity_class": "User"', $json);
    }
}
