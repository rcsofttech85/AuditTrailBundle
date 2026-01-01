<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

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
        $this->service = new AuditIntegrityService('secret', 'sha256', true);
    }

    public function testVerifySignatureAllowsRevertWithoutSignature(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_REVERT);
        $log->setSignature(null);

        self::assertTrue($this->service->verifySignature($log));
    }

    public function testVerifySignatureFailsForOtherActionsWithoutSignature(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setSignature(null);

        self::assertFalse($this->service->verifySignature($log));
    }

    public function testVerifySignatureWorksForRevertWithSignature(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_REVERT);
        $log->setEntityClass('App\Entity\User');
        $log->setEntityId('1');
        $log->setOldValues(['name' => 'John']);
        $log->setNewValues(null);
        $log->setCreatedAt(new \DateTimeImmutable());

        $signature = $this->service->generateSignature($log);
        $log->setSignature($signature);

        self::assertTrue($this->service->verifySignature($log));

        // Tamper it
        $log->setEntityId('2');
        self::assertFalse($this->service->verifySignature($log));
    }
}
