<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use stdClass;
use Stringable;

use function strlen;

final class AuditIntegrityServiceObjectTypeTest extends TestCase
{
    private AuditIntegrityService $service;

    protected function setUp(): void
    {
        $this->service = new AuditIntegrityService('test-secret', true, 'sha256');
    }

    public function testDateTimeObjectInValuesDoesNotCrash(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => new DateTime('2026-01-20 10:00:00', new DateTimeZone('UTC'))],
            newValues: ['publishedAt' => new DateTime('2026-01-22 12:00:00', new DateTimeZone('UTC'))],
        );

        $signature = $this->service->generateSignature($log);

        self::assertNotEmpty($signature);
        self::assertSame(64, strlen($signature));
    }

    /**
     * Verify that DateTimeImmutable objects in values also work correctly.
     */
    public function testDateTimeImmutableObjectInValuesDoesNotCrash(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00'),
            oldValues: ['publishedAt' => new DateTimeImmutable('2026-01-20 10:00:00', new DateTimeZone('UTC'))],
            newValues: ['publishedAt' => new DateTimeImmutable('2026-01-22 12:00:00', new DateTimeZone('UTC'))],
        );

        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);
    }

    /**
     * Signature stability: DateTime and DateTimeImmutable representing the
     * same instant must produce the same signature.
     */
    public function testDateTimeAndDateTimeImmutableProduceSameSignature(): void
    {
        $logMutable = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => new DateTime('2026-01-20 10:00:00', new DateTimeZone('UTC'))],
        );

        $logImmutable = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => new DateTimeImmutable('2026-01-20 10:00:00', new DateTimeZone('UTC'))],
        );

        self::assertSame(
            $this->service->generateSignature($logMutable),
            $this->service->generateSignature($logImmutable),
            'DateTime and DateTimeImmutable for the same instant must produce identical signatures.'
        );
    }

    /**
     * Signature stability: DateTime objects in different timezones but
     * representing the same instant must produce the same signature.
     */
    public function testDateTimeObjectsWithDifferentTimezonesProduceSameSignature(): void
    {
        $logUtc = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => new DateTime('2026-01-20 10:00:00', new DateTimeZone('UTC'))],
        );

        $logIst = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => new DateTime('2026-01-20 15:30:00', new DateTimeZone('Asia/Kolkata'))],
        );

        self::assertSame(
            $this->service->generateSignature($logUtc),
            $this->service->generateSignature($logIst),
            'DateTime objects for the same instant in different timezones must produce identical signatures.'
        );
    }

    /**
     * An object with __toString should be serialized as a string.
     */
    public function testStringableObjectInValuesDoesNotCrash(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable(),
            oldValues: ['tag' => $stringable],
        );

        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);
    }

    /**
     * An object WITHOUT __toString should NOT crash — it should fall back
     * to 'o:<classname>'.
     */
    public function testNonStringableObjectInValuesDoesNotCrash(): void
    {
        $obj = new stdClass();

        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable(),
            oldValues: ['metadata' => $obj],
        );

        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);
    }

    /**
     * DateTime objects inside the context array should also be handled.
     */
    public function testDateTimeInContextDoesNotCrash(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'create',
            createdAt: new DateTimeImmutable(),
            context: ['triggered_at' => new DateTime('2026-01-20 10:00:00')],
        );

        $signature = $this->service->generateSignature($log);
        self::assertNotEmpty($signature);
    }

    /**
     * Signature must remain stable across multiple calls with the same data.
     */
    public function testSignatureIdempotencyWithDateTimeObjects(): void
    {
        $dt = new DateTimeImmutable('2026-01-20 10:00:00', new DateTimeZone('UTC'));

        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => $dt],
        );

        $sig1 = $this->service->generateSignature($log);
        $sig2 = $this->service->generateSignature($log);

        self::assertSame($sig1, $sig2, 'Signatures must be idempotent.');
    }

    /**
     * Verify that the signature validates correctly when the log contains
     * DateTime objects — the full round-trip from sign to verify.
     */
    public function testSignAndVerifyWithDateTimeObjects(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-01-22 08:00:00', new DateTimeZone('UTC')),
            oldValues: ['publishedAt' => new DateTime('2026-01-20 10:00:00', new DateTimeZone('UTC'))],
            newValues: ['publishedAt' => new DateTime('2026-01-22 12:00:00', new DateTimeZone('UTC'))],
        );

        $log->signature = $this->service->generateSignature($log);

        self::assertTrue(
            $this->service->verifySignature($log),
            'Signature verification must pass for logs with DateTime objects.'
        );
    }
}
