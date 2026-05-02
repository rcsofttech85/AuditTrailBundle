<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityNormalizer;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;

final class AuditIntegrityRevertTest extends TestCase
{
    private AuditIntegrityService $service;

    protected function setUp(): void
    {
        $this->service = new AuditIntegrityService(new AuditIntegrityNormalizer(), 'secret', true, 'sha256');
    }

    public function testVerifySignatureRejectsRevertWithoutSignature(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditAction::Revert);
        $log->signature = null;

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureFailsForOtherActionsWithoutSignature(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditAction::Update);
        $log->signature = null;

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureWorksForRevertWithSignature(): void
    {
        $log = new AuditLog(
            'App\Entity\User',
            '1',
            AuditAction::Revert,
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
