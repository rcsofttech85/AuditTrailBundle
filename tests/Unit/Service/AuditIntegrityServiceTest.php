<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use ReflectionClass;
use RuntimeException;

use function strlen;

#[AllowMockObjectsWithoutExpectations]
final class AuditIntegrityServiceTest extends TestCase
{
    private AuditIntegrityService $service;

    private string $secret = 'test-secret';

    protected function setUp(): void
    {
        $this->service = new AuditIntegrityService($this->secret, true, 'sha256');
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->service->isEnabled());

        $disabledService = new AuditIntegrityService($this->secret, false, 'sha256');
        self::assertFalse($disabledService->isEnabled());
    }

    public function testGenerateSignature(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name'],
            userId: '42',
            username: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla/5.0',
            transactionHash: 'abc-123'
        );

        $signature = $this->service->generateSignature($log);

        self::assertNotEmpty($signature);
        self::assertSame(64, strlen($signature)); // sha256 is 64 chars in hex
    }

    public function testVerifySignatureSuccess(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name']
        );

        $signature = $this->service->generateSignature($log);
        $log->signature = $signature;

        self::assertTrue($this->service->verifySignature($log));
    }

    public function testVerifySignatureFailure(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'update');
        $log->signature = 'invalid-signature';

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureWithTamperedData(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name']
        );

        $signature = $this->service->generateSignature($log);

        $tamperedLog = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'TAMPERED Name']
        );
        $tamperedLog->signature = $signature;

        self::assertFalse($this->service->verifySignature($tamperedLog));
    }

    public function testVerifySignatureWithTamperedEntityClass(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'update');
        $signature = $this->service->generateSignature($log);

        $tamperedLog = new AuditLog('App\Entity\Post', '1', 'update');
        $tamperedLog->signature = $signature;

        self::assertFalse($this->service->verifySignature($tamperedLog));
    }

    public function testVerifySignatureFailsOnTypeMismatch(): void
    {
        // Create a log with integer ID in values
        $logInt = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 12:00:00'),
            ['author_id' => 1]
        );

        $signature = $this->service->generateSignature($logInt);

        // Create a log with string ID in values but same logical data
        $logStr = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 12:00:00'),
            ['author_id' => '1']
        );
        $logStr->signature = $signature;

        // Should now FAIL because i:1 != s:1
        self::assertFalse($this->service->verifySignature($logStr));
    }

    public function testNormalizeDepthLimit(): void
    {
        $deepArray = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too_deep']]]]]];
        $log = new AuditLog('Test', '1', 'create', new DateTimeImmutable(), $deepArray);

        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);

        // Manual check of normalization behavior (internal)
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeValues');
        $result = $method->invoke($this->service, $deepArray);

        self::assertEquals('s:[max_depth]', $result['a']['b']['c']['d']['e']);
    }

    public function testVerifySignatureWithTimezoneStability(): void
    {
        // Create a log with UTC timezone
        $logUtc = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 12:00:00', new DateTimeZone('UTC')),
            ['name' => 'Old']
        );

        $signature = $this->service->generateSignature($logUtc);

        // Create a log with different timezone but same point in time
        $logIst = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 17:30:00', new DateTimeZone('Asia/Kolkata')),
            ['name' => 'Old']
        );
        $logIst->signature = $signature;

        // Should pass because we normalize to UTC before hashing
        self::assertTrue($this->service->verifySignature($logIst));
    }

    public function testVerifySignatureWithDateArrayStability(): void
    {
        // Log with ATOM string date (new format)
        $logAtom = new AuditLog(
            'App\Entity\Post',
            '92',
            'update',
            new DateTimeImmutable('2026-01-22 08:05:06'),
            ['createdAt' => '2026-01-22T08:04:32+00:00']
        );

        $signature = $this->service->generateSignature($logAtom);

        // Log with array-represented date (old format with UTC timezone)
        $logArrayUtc = new AuditLog(
            'App\Entity\Post',
            '92',
            'update',
            new DateTimeImmutable('2026-01-22 08:05:06'),
            [
                'createdAt' => [
                    'date' => '2026-01-22 08:04:32.000000',
                    'timezone' => 'UTC',
                    'timezone_type' => 3,
                ],
            ]
        );
        $logArrayUtc->signature = $signature;

        self::assertTrue($this->service->verifySignature($logArrayUtc));
    }

    public function testSignPayload(): void
    {
        $signature = $this->service->signPayload('test');
        self::assertNotEmpty($signature);

        $disabledService = new AuditIntegrityService(null, true, 'sha256');
        $this->expectException(RuntimeException::class);
        $disabledService->signPayload('test');
    }

    public function testGenerateSignatureNoSecret(): void
    {
        $disabledService = new AuditIntegrityService(null, true, 'sha256');
        $this->expectException(RuntimeException::class);
        $disabledService->generateSignature(new AuditLog('a', '1', 'create'));
    }

    public function testNormalizePrimitives(): void
    {
        $log = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable(),
            [
                'null_val' => null,
                'bool_true' => true,
                'bool_false' => false,
                'int_val' => 42,
                'float_val' => 3.14,
                'normal_str' => 'text',
                'date_str' => '2023-01-01 12:00:00',
            ]
        );
        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);
    }

    public function testNormalizeValuesDepthLimitReached(): void
    {
        $log = new AuditLog('Test', '1', 'update');
        $reflectionLog = new ReflectionClass($log);
        $property = $reflectionLog->getProperty('oldValues');
        $property->setValue($log, ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'g']]]]]]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeValues');

        $result = $method->invoke($this->service, $log->oldValues);
        // Depth logic internal check, maximum depth replaces value with something max_depth_reached
        self::assertEquals('s:[max_depth]', $result['a']['b']['c']['d']['e']);

        // Depth 0
        $result = $method->invoke($this->service, $log->oldValues, 4);
        self::assertEquals(['a' => 's:[max_depth]'], $result);
    }

    public function testNormalizeValuesMaxDepth(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeValues');
        $result = $method->invoke($this->service, ['a' => 'b'], 5); // 5 is max depth
        self::assertEquals(['_error' => 'max_depth_reached'], $result);
    }
}
