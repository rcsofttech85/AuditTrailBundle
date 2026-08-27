<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityNormalizer;
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
        $this->service = $this->createService($this->secret, true, 'sha256');
    }

    private function createService(?string $secret, bool $enabled, string $algorithm): AuditIntegrityService
    {
        return new AuditIntegrityService(new AuditIntegrityNormalizer(), $secret, $enabled, $algorithm);
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->service->isEnabled());

        $disabledService = $this->createService($this->secret, false, 'sha256');
        self::assertFalse($disabledService->isEnabled());
    }

    /**
     * A DATETIME column carries no timezone: Doctrine writes the digits the object happens to hold
     * and hands those same digits back reinterpreted in PHP's default timezone. Signing an instant
     * therefore signs something the round trip cannot reproduce — anywhere PHP is not on UTC, a row
     * verifies at write time and reports itself tampered with the moment it is read back.
     *
     * Pinned to a non-UTC default on purpose: on a UTC runner the two forms coincide and this test
     * would pass no matter what the normalizer does.
     */
    public function testVerifySignatureSurvivesADoctrineRoundTripUnderANonUtcDefaultTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $written = $this->logCreatedAt(new DateTimeImmutable('2026-08-24 07:34:52', new DateTimeZone('UTC')));
            $signature = $this->service->generateSignature($written);

            // What Doctrine gives back: the stored digits, read in PHP's default timezone.
            $reloaded = $this->logCreatedAt(new DateTimeImmutable('2026-08-24 07:34:52'));
            $reloaded->signature = $signature;

            self::assertTrue($this->service->verifySignature($reloaded));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * Tamper detection must not soften on the very hosts the round-trip fix targets. Shifting
     * created_at by exactly the host's UTC offset is the one edit that would slip past a verifier
     * willing to accept the pre-fix, UTC-converted form as well as the stored one.
     */
    public function testAShiftedCreatedAtIsStillDetectedUnderANonUtcDefaultTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $written = $this->logCreatedAt(new DateTimeImmutable('2026-08-24 07:34:52', new DateTimeZone('UTC')));
            $signature = $this->service->generateSignature($written);

            $backdated = $this->logCreatedAt(new DateTimeImmutable('2026-08-24 09:34:52'));
            $backdated->signature = $signature;

            self::assertFalse($this->service->verifySignature($backdated));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * Why this invalidates nothing already in the wild: audit_trail.timezone defaults to 'UTC', so
     * AuditLogFactory hands the signer an already-UTC object and the conversion the previous
     * implementation performed had nothing to convert. Both forms must be byte-identical here.
     */
    public function testTheStoredFormMatchesThePreviousUtcConvertedFormWhenStampedInUtc(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-24 07:34:52', new DateTimeZone('UTC'));
        $log = $this->logCreatedAt($createdAt);

        self::assertSame(
            $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            new AuditIntegrityNormalizer()->normalize($log)['created_at'],
        );
    }

    private function logCreatedAt(DateTimeImmutable $createdAt): AuditLog
    {
        return new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            createdAt: $createdAt,
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name'],
            userId: '42',
            username: 'admin',
        );
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

    /**
     * The signature follows the digits that get stored, not the instant they denote.
     *
     * This used to assert the opposite — that the same moment written in two zones verified against
     * one signature — but that property cannot survive persistence. created_at is a DATETIME and
     * keeps no offset, so Doctrine writes whatever digits the object holds and hands them back in
     * PHP's default timezone; a reloaded row only ever carries one zone, and the instant it
     * originally denoted is not recoverable. Honouring the old property also meant a verifier had
     * to accept several distinct timestamps for a single signature, which is precisely how a row
     * shifted by the host's UTC offset would have slipped through.
     */
    public function testSignatureFollowsTheStoredDigitsRatherThanTheInstant(): void
    {
        $logUtc = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 12:00:00', new DateTimeZone('UTC')),
            ['name' => 'Old']
        );

        $signature = $this->service->generateSignature($logUtc);

        // Same digits, different zone: what Doctrine reconstructs on a non-UTC host.
        $sameDigits = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 12:00:00', new DateTimeZone('Asia/Kolkata')),
            ['name' => 'Old']
        );
        $sameDigits->signature = $signature;
        self::assertTrue($this->service->verifySignature($sameDigits));

        // Same instant, different digits: no longer the row that was signed.
        $sameInstant = new AuditLog(
            'App\Entity\User',
            '1',
            'update',
            new DateTimeImmutable('2023-01-01 17:30:00', new DateTimeZone('Asia/Kolkata')),
            ['name' => 'Old']
        );
        $sameInstant->signature = $signature;
        self::assertFalse($this->service->verifySignature($sameInstant));
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

    public function testVerifySignatureWithSpaceSeparatedDateStringStability(): void
    {
        $log = new AuditLog(
            'App\\Entity\\Post',
            '92',
            'update',
            new DateTimeImmutable('2026-01-22 08:05:06'),
            ['createdAt' => '2026-01-22 08:04:32+00:00']
        );

        $signature = $this->service->generateSignature($log);
        $log->signature = $signature;

        self::assertTrue($this->service->verifySignature($log));
    }

    public function testSignPayload(): void
    {
        $signature = $this->service->signPayload('test');
        self::assertNotEmpty($signature);

        $disabledService = $this->createService(null, true, 'sha256');
        $this->expectException(RuntimeException::class);
        $disabledService->signPayload('test');
    }

    public function testGenerateSignatureNoSecret(): void
    {
        $disabledService = $this->createService(null, true, 'sha256');
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
