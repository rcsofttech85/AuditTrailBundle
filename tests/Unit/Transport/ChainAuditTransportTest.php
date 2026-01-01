<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;

#[AllowMockObjectsWithoutExpectations]
class ChainAuditTransportTest extends TestCase
{
    public function testSupportsReturnsTrueIfAnyTransportSupportsPhase(): void
    {
        $t1 = self::createStub(AuditTransportInterface::class);
        $t1->method('supports')->willReturnMap([['on_flush', true]]);

        $t2 = self::createStub(AuditTransportInterface::class);
        $t2->method('supports')->willReturnMap([['on_flush', false]]);

        $chain = new ChainAuditTransport([$t1, $t2]);

        self::assertTrue($chain->supports('on_flush'), 'Chain should support phase if at least one child supports it');
    }

    public function testSupportsReturnsFalseIfNoTransportSupportsPhase(): void
    {
        $t1 = self::createStub(AuditTransportInterface::class);
        $t1->method('supports')->willReturnMap([['on_flush', false]]);

        $t2 = self::createStub(AuditTransportInterface::class);
        $t2->method('supports')->willReturnMap([['on_flush', false]]);

        $chain = new ChainAuditTransport([$t1, $t2]);

        self::assertFalse($chain->supports('on_flush'));
    }

    public function testSendOnlyCallsTransportsThatSupportThePhase(): void
    {
        $log = new AuditLog();
        $context = ['phase' => 'on_flush'];

        $t1 = $this->createMock(AuditTransportInterface::class);
        $t1->method('supports')->with('on_flush')->willReturn(true);
        $t1->expects($this->once())->method('send')->with($log, $context);

        $t2 = $this->createMock(AuditTransportInterface::class);
        $t2->method('supports')->with('on_flush')->willReturn(false);
        $t2->expects($this->never())->method('send');

        $chain = new ChainAuditTransport([$t1, $t2]);
        $chain->send($log, $context);
    }
}
