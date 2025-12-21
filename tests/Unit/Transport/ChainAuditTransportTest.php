<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;

class ChainAuditTransportTest extends TestCase
{
    public function testSendDelegatesToAllTransports(): void
    {
        $log = new AuditLog();
        $context = ['phase' => 'test'];

        $t1 = $this->createMock(AuditTransportInterface::class);
        $t1->expects($this->once())->method('send')->with($log, $context);

        $t2 = $this->createMock(AuditTransportInterface::class);
        $t2->expects($this->once())->method('send')->with($log, $context);

        $chain = new ChainAuditTransport([$t1, $t2]);
        $chain->send($log, $context);
    }
}
