<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;

#[AllowMockObjectsWithoutExpectations]
final class AuditIntegrityServiceTest extends TestCase
{
    private AuditIntegrityService $service;
    private string $secret = 'test-secret';

    protected function setUp(): void
    {
        $this->service = new AuditIntegrityService($this->secret, 'sha256', true);
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->service->isEnabled());

        $disabledService = new AuditIntegrityService($this->secret, 'sha256', false);
        self::assertFalse($disabledService->isEnabled());
    }

    public function testGenerateSignature(): void
    {
        $log = self::createStub(AuditLogInterface::class);
        $log->method('getEntityClass')->willReturn('App\Entity\User');
        $log->method('getEntityId')->willReturn('1');
        $log->method('getAction')->willReturn('update');
        $log->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $log->method('getNewValues')->willReturn(['name' => 'New Name']);
        $log->method('getUserId')->willReturn('42');
        $log->method('getUsername')->willReturn('admin');
        $log->method('getIpAddress')->willReturn('127.0.0.1');
        $log->method('getUserAgent')->willReturn('Mozilla/5.0');
        $log->method('getTransactionHash')->willReturn('abc-123');
        $log->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $signature = $this->service->generateSignature($log);

        self::assertNotEmpty($signature);
        self::assertSame(64, strlen($signature)); // sha256 is 64 chars in hex
    }

    public function testVerifySignatureSuccess(): void
    {
        $log = self::createStub(AuditLogInterface::class);
        $log->method('getEntityClass')->willReturn('App\Entity\User');
        $log->method('getEntityId')->willReturn('1');
        $log->method('getAction')->willReturn('update');
        $log->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $log->method('getNewValues')->willReturn(['name' => 'New Name']);
        $log->method('getUserId')->willReturn('42');
        $log->method('getUsername')->willReturn('admin');
        $log->method('getIpAddress')->willReturn('127.0.0.1');
        $log->method('getUserAgent')->willReturn('Mozilla/5.0');
        $log->method('getTransactionHash')->willReturn('abc-123');
        $log->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $signature = $this->service->generateSignature($log);
        $log->method('getSignature')->willReturn($signature);

        self::assertTrue($this->service->verifySignature($log));
    }

    public function testVerifySignatureFailure(): void
    {
        $log = self::createStub(AuditLogInterface::class);
        $log->method('getEntityClass')->willReturn('App\Entity\User');
        $log->method('getEntityId')->willReturn('1');
        $log->method('getAction')->willReturn('update');
        $log->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $log->method('getNewValues')->willReturn(['name' => 'New Name']);
        $log->method('getUserId')->willReturn('42');
        $log->method('getUsername')->willReturn('admin');
        $log->method('getIpAddress')->willReturn('127.0.0.1');
        $log->method('getUserAgent')->willReturn('Mozilla/5.0');
        $log->method('getTransactionHash')->willReturn('abc-123');
        $log->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $log->method('getSignature')->willReturn('invalid-signature');

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureWithTamperedData(): void
    {
        $log = self::createStub(AuditLogInterface::class);
        $log->method('getEntityClass')->willReturn('App\Entity\User');
        $log->method('getEntityId')->willReturn('1');
        $log->method('getAction')->willReturn('update');
        $log->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $log->method('getNewValues')->willReturn(['name' => 'New Name']);
        $log->method('getUserId')->willReturn('42');
        $log->method('getUsername')->willReturn('admin');
        $log->method('getIpAddress')->willReturn('127.0.0.1');
        $log->method('getUserAgent')->willReturn('Mozilla/5.0');
        $log->method('getTransactionHash')->willReturn('abc-123');
        $log->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $signature = $this->service->generateSignature($log);

        // Create a new stub with tampered data but same signature
        $tamperedLog = self::createStub(AuditLogInterface::class);
        $tamperedLog->method('getEntityClass')->willReturn('App\Entity\User');
        $tamperedLog->method('getEntityId')->willReturn('1');
        $tamperedLog->method('getAction')->willReturn('update');
        $tamperedLog->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $tamperedLog->method('getNewValues')->willReturn(['name' => 'TAMPERED Name']); // Tampered!
        $tamperedLog->method('getUserId')->willReturn('42');
        $tamperedLog->method('getUsername')->willReturn('admin');
        $tamperedLog->method('getIpAddress')->willReturn('127.0.0.1');
        $tamperedLog->method('getUserAgent')->willReturn('Mozilla/5.0');
        $tamperedLog->method('getTransactionHash')->willReturn('abc-123');
        $tamperedLog->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));
        $tamperedLog->method('getSignature')->willReturn($signature);

        self::assertFalse($this->service->verifySignature($tamperedLog));
    }

    public function testVerifySignatureWithTamperedEntityClass(): void
    {
        $log = self::createStub(AuditLogInterface::class);
        $log->method('getEntityClass')->willReturn('App\Entity\User');
        $log->method('getEntityId')->willReturn('1');
        $log->method('getAction')->willReturn('update');
        $log->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $log->method('getNewValues')->willReturn(['name' => 'New Name']);
        $log->method('getUserId')->willReturn('42');
        $log->method('getUsername')->willReturn('admin');
        $log->method('getIpAddress')->willReturn('127.0.0.1');
        $log->method('getUserAgent')->willReturn('Mozilla/5.0');
        $log->method('getTransactionHash')->willReturn('abc-123');
        $log->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $signature = $this->service->generateSignature($log);

        $tamperedLog = self::createStub(AuditLogInterface::class);
        $tamperedLog->method('getEntityClass')->willReturn('App\Entity\Post'); // Tampered!
        $tamperedLog->method('getEntityId')->willReturn('1');
        $tamperedLog->method('getAction')->willReturn('update');
        $tamperedLog->method('getOldValues')->willReturn(['name' => 'Old Name']);
        $tamperedLog->method('getNewValues')->willReturn(['name' => 'New Name']);
        $tamperedLog->method('getUserId')->willReturn('42');
        $tamperedLog->method('getUsername')->willReturn('admin');
        $tamperedLog->method('getIpAddress')->willReturn('127.0.0.1');
        $tamperedLog->method('getUserAgent')->willReturn('Mozilla/5.0');
        $tamperedLog->method('getTransactionHash')->willReturn('abc-123');
        $tamperedLog->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));
        $tamperedLog->method('getSignature')->willReturn($signature);

        self::assertFalse($this->service->verifySignature($tamperedLog));
    }

    public function testVerifySignatureWithTypeStability(): void
    {
        // Create a log with integer ID in values
        $logInt = self::createStub(AuditLogInterface::class);
        $logInt->method('getEntityClass')->willReturn('App\Entity\User');
        $logInt->method('getEntityId')->willReturn('1');
        $logInt->method('getAction')->willReturn('update');
        $logInt->method('getOldValues')->willReturn(['author_id' => 1]); // Integer ID
        $logInt->method('getNewValues')->willReturn(['author_id' => 1]);
        $logInt->method('getUserId')->willReturn('42');
        $logInt->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));

        $signature = $this->service->generateSignature($logInt);

        // Create a log with string ID in values but same logical data
        $logStr = self::createStub(AuditLogInterface::class);
        $logStr->method('getEntityClass')->willReturn('App\Entity\User');
        $logStr->method('getEntityId')->willReturn('1');
        $logStr->method('getAction')->willReturn('update');
        $logStr->method('getOldValues')->willReturn(['author_id' => '1']); // String ID
        $logStr->method('getNewValues')->willReturn(['author_id' => '1']);
        $logStr->method('getUserId')->willReturn('42');
        $logStr->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00'));
        $logStr->method('getSignature')->willReturn($signature);

        // Should pass despite type difference
        self::assertTrue($this->service->verifySignature($logStr));
    }

    public function testVerifySignatureWithTimezoneStability(): void
    {
        // Create a log with UTC timezone
        $logUtc = self::createStub(AuditLogInterface::class);
        $logUtc->method('getEntityClass')->willReturn('App\Entity\User');
        $logUtc->method('getEntityId')->willReturn('1');
        $logUtc->method('getAction')->willReturn('update');
        $logUtc->method('getOldValues')->willReturn(['name' => 'Old']);
        $logUtc->method('getNewValues')->willReturn(['name' => 'New']);
        $logUtc->method('getUserId')->willReturn('42');
        $logUtc->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 12:00:00', new \DateTimeZone('UTC')));

        $signature = $this->service->generateSignature($logUtc);

        // Create a log with different timezone but same point in time
        $logIst = self::createStub(AuditLogInterface::class);
        $logIst->method('getEntityClass')->willReturn('App\Entity\User');
        $logIst->method('getEntityId')->willReturn('1');
        $logIst->method('getAction')->willReturn('update');
        $logIst->method('getOldValues')->willReturn(['name' => 'Old']);
        $logIst->method('getNewValues')->willReturn(['name' => 'New']);
        $logIst->method('getUserId')->willReturn('42');
        // 2023-01-01 12:00:00 UTC is 2023-01-01 17:30:00 IST
        $logIst->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01 17:30:00', new \DateTimeZone('Asia/Kolkata')));
        $logIst->method('getSignature')->willReturn($signature);

        // Should pass because we normalize to UTC before hashing
        self::assertTrue($this->service->verifySignature($logIst));
    }

    public function testVerifySignatureWithDateArrayStability(): void
    {
        // Log with ATOM string date (new format)
        $logAtom = self::createStub(AuditLogInterface::class);
        $logAtom->method('getEntityClass')->willReturn('App\Entity\Post');
        $logAtom->method('getEntityId')->willReturn('92');
        $logAtom->method('getAction')->willReturn('update');
        $logAtom->method('getOldValues')->willReturn(['createdAt' => '2026-01-22T08:04:32+00:00']);
        $logAtom->method('getNewValues')->willReturn(['createdAt' => '2025-12-22T02:01:21+00:00']);
        $logAtom->method('getUserId')->willReturn('1');
        $logAtom->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2026-01-22 08:05:06'));

        $signature = $this->service->generateSignature($logAtom);

        // Log with array-represented date (old format with UTC timezone)
        $logArrayUtc = self::createStub(AuditLogInterface::class);
        $logArrayUtc->method('getEntityClass')->willReturn('App\Entity\Post');
        $logArrayUtc->method('getEntityId')->willReturn('92');
        $logArrayUtc->method('getAction')->willReturn('update');
        $logArrayUtc->method('getOldValues')->willReturn([
            'createdAt' => [
                'date' => '2026-01-22 08:04:32.000000',
                'timezone' => 'UTC',
                'timezone_type' => 3,
            ],
        ]);
        $logArrayUtc->method('getNewValues')->willReturn([
            'createdAt' => [
                'date' => '2025-12-22 02:01:21.424000',
                'timezone' => 'Z',
                'timezone_type' => 2,
            ],
        ]);
        $logArrayUtc->method('getUserId')->willReturn('1');
        $logArrayUtc->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2026-01-22 08:05:06'));
        $logArrayUtc->method('getSignature')->willReturn($signature);

        self::assertTrue($this->service->verifySignature($logArrayUtc));
    }
}
