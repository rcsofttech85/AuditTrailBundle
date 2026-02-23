<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
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

    public function testSanitizeCsvValueWithAtAndMinus(): void
    {
        $log = new AuditLog('User', '1', 'update', username: '-malicious', ipAddress: '127.0.0.1');

        $csv = $this->exporter->formatAsCsv([$log]);

        self::assertStringContainsString("'-malicious", $csv);
        self::assertStringContainsString('127.0.0.1', $csv);
    }

    public function testFormatAuditsThrowsOnInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported format: xml');
        $this->exporter->formatAudits([], 'xml');
    }

    public function testFormatFileSize(): void
    {
        self::assertSame('0 B', $this->exporter->formatFileSize(0));
        self::assertSame('0 B', $this->exporter->formatFileSize(-100));
        self::assertSame('500.00 B', $this->exporter->formatFileSize(500));
        self::assertSame('1.00 KB', $this->exporter->formatFileSize(1024));
        self::assertSame('1.50 KB', $this->exporter->formatFileSize(1536));
        self::assertSame('1.00 MB', $this->exporter->formatFileSize(1024 * 1024));
        self::assertSame('1.00 GB', $this->exporter->formatFileSize(1024 * 1024 * 1024));
        self::assertSame('1.00 TB', $this->exporter->formatFileSize(1024 ** 4));
        self::assertSame('1.00 PB', $this->exporter->formatFileSize(1024 ** 5));
        self::assertSame('1.00 EB', $this->exporter->formatFileSize(1024 ** 6));
    }

    public function testFormatAuditsJson(): void
    {
        $log1 = new AuditLog('User', '1', 'create', new DateTimeImmutable('2024-01-01 12:00:00'));
        $log2 = new AuditLog('User', '2', 'update', new DateTimeImmutable('2024-01-02 12:00:00'));

        // This direct call will kill the PublicVisibility mutant on formatAsJson
        $json = $this->exporter->formatAsJson([$log1, $log2]);

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);

        self::assertSame('User', $decoded[0]['entity_class']);
        self::assertSame('1', $decoded[0]['entity_id']);
        self::assertSame('create', $decoded[0]['action']);
        self::assertArrayHasKey('old_values', $decoded[0]);
        self::assertArrayHasKey('new_values', $decoded[0]);
        self::assertArrayHasKey('changed_fields', $decoded[0]);
        self::assertArrayHasKey('user_id', $decoded[0]);
        self::assertArrayHasKey('username', $decoded[0]);
        self::assertArrayHasKey('ip_address', $decoded[0]);
        self::assertArrayHasKey('user_agent', $decoded[0]);
        self::assertSame('2024-01-01T12:00:00+00:00', $decoded[0]['created_at']);

        // Test JSON formatting flags by strictly checking string layout
        // Assuming PRETTY_PRINT adds newlines and JSON_UNESCAPED_SLASHES prevents \/
        self::assertStringContainsString("[{\n    \"id\":", $json);
        self::assertStringNotContainsString('\/', $json);
    }

    public function testFormatAuditsCsv(): void
    {
        $log1 = new AuditLog('User', '1', 'create', new DateTimeImmutable('2024-01-01 12:00:00'));
        $log2 = new AuditLog('Post', '99', 'delete', new DateTimeImmutable('2024-01-02 12:00:00'));

        // Direct call to kill PublicVisibility mutant
        $csv = $this->exporter->formatAsCsv([$log1, $log2]);

        $lines = explode("\n", trim($csv));
        self::assertCount(3, $lines);

        // Header array keys checking
        self::assertStringContainsString('id,entity_class,entity_id,action,old_values,new_values,changed_fields,user_id,username,ip_address,user_agent,created_at', $lines[0]);

        // Check exact row layouts
        self::assertStringContainsString(',User,1,create,,,,,,,,2024-01-01T12:00:00+00:00', $lines[1]);
        self::assertStringContainsString(',Post,99,delete,,,,,,,,2024-01-02T12:00:00+00:00', $lines[2]);
    }

    public function testAuditToArrayVisibility(): void
    {
        // Testing that auditToArray is public, will cause PublicVisibility to be caught if it is made protected
        $log = new AuditLog('User', '1', 'create');
        $array = $this->exporter->auditToArray($log);
        self::assertArrayHasKey('action', $array);
    }
}
