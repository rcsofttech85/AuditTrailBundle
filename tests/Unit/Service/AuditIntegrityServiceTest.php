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
}
