<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;

#[CoversClass(AuditIntegrityService::class)]
#[AllowMockObjectsWithoutExpectations]
class AuditIntegrityRevertTest extends TestCase
{
    private AuditIntegrityService $service;

    protected function setUp(): void
    {
        $this->service = new AuditIntegrityService('secret', true, 'sha256');
    }

    public function testVerifySignatureRejectsRevertWithoutSignature(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_REVERT);
        $log->signature = null;

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureFailsForOtherActionsWithoutSignature(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_UPDATE);
        $log->signature = null;

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureWorksForRevertWithSignature(): void
    {
        $log = new AuditLog(
            'App\Entity\User',
            '1',
            AuditLogInterface::ACTION_REVERT,
            new DateTimeImmutable('2024-01-01 12:00:00'),
            ['name' => 'John']
        );

        $signature = $this->service->generateSignature($log);
        $log->signature = $signature;

        self::assertTrue($this->service->verifySignature($log));

        // Tamper it
        $log->entityId = '2';
        self::assertFalse($this->service->verifySignature($log));
    }
}
