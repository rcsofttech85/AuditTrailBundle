<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

class AuditLogTest extends TestCase
{
    public function testTransactionHash(): void
    {
        $log = new AuditLog();
        self::assertNull($log->getTransactionHash());

        $hash = 'test-hash';
        $log->setTransactionHash($hash);
        self::assertEquals($hash, $log->getTransactionHash());

        $log->setTransactionHash(null);
        self::assertNull($log->getTransactionHash());
    }
}
