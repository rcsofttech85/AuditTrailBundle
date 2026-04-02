<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use RuntimeException;

use function hash_hmac;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

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

    public function testVerifySignatureFailsWhenChangedFieldsAreTampered(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name'],
            changedFields: ['name']
        );

        $signature = $this->service->generateSignature($log);

        $tamperedLog = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name'],
            changedFields: ['email']
        );
        $tamperedLog->signature = $signature;

        self::assertFalse($this->service->verifySignature($tamperedLog));
    }

    public function testVerifySignatureAcceptsLegacySignatureWithoutChangedFields(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2023-01-01 12:00:00'),
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name'],
            changedFields: ['name']
        );

        $legacyPayload = json_encode([
            'action' => 'update',
            'context' => [],
            'created_at' => '2023-01-01 12:00:00',
            'entity_class' => 'App\Entity\User',
            'entity_id' => '1',
            'ip_address' => null,
            'new_values' => ['name' => 's:New Name'],
            'old_values' => ['name' => 's:Old Name'],
            'transaction_hash' => null,
            'user_agent' => null,
            'user_id' => null,
            'username' => null,
        ], JSON_THROW_ON_ERROR);

        $log->signature = hash_hmac('sha256', $legacyPayload, $this->secret);

        self::assertTrue($this->service->verifySignature($log));
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

    public function testDeepNestedValuesProduceStableSignatures(): void
    {
        $deepArray = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too_deep']]]]]];
        $log = new AuditLog('Test', '1', 'create', new DateTimeImmutable(), $deepArray);

        $signature1 = $this->service->generateSignature($log);
        $signature2 = $this->service->generateSignature($log);

        self::assertNotEmpty($signature1);
        self::assertSame($signature1, $signature2, 'Deep nested data should produce deterministic signatures');

        // Verify that the signature is valid
        $log->signature = $signature1;
        self::assertTrue($this->service->verifySignature($log));
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

    public function testDeeplyNestedValuesDoNotBreakSigning(): void
    {
        $deepValues = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'g']]]]]];
        $log = new AuditLog(
            'Test',
            '1',
            'update',
            new DateTimeImmutable(),
            $deepValues,
            ['x' => 'y']
        );

        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);

        $log->signature = $signature;
        self::assertTrue($this->service->verifySignature($log));
    }

    public function testShallowValuesProduceDifferentSignatureThanDeep(): void
    {
        $shallow = new AuditLog('Test', '1', 'update', new DateTimeImmutable(), ['a' => 'b']);
        $deep = new AuditLog('Test', '1', 'update', new DateTimeImmutable(), ['a' => ['b' => 'c']]);

        $sig1 = $this->service->generateSignature($shallow);
        $sig2 = $this->service->generateSignature($deep);

        self::assertNotSame($sig1, $sig2, 'Different nesting depths should produce different signatures');
    }
}
