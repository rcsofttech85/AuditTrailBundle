<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;
use RuntimeException;

final class ChainAuditTransportTest extends TestCase
{
    public function testSupportsReturnsTrueIfAnyTransportSupportsPhase(): void
    {
        $t1 = self::createStub(AuditTransportInterface::class);
        $t1->method('supports')->willReturn(true);

        $t2 = self::createStub(AuditTransportInterface::class);
        $t2->method('supports')->willReturn(false);

        $chain = new ChainAuditTransport([$t1, $t2]);

        self::assertTrue($chain->supports($this->createContext(AuditPhase::OnFlush)), 'Chain should support phase if at least one child supports it');
    }

    public function testSupportsReturnsFalseIfNoTransportSupportsPhase(): void
    {
        $t1 = self::createStub(AuditTransportInterface::class);
        $t1->method('supports')->willReturn(false);

        $t2 = self::createStub(AuditTransportInterface::class);
        $t2->method('supports')->willReturn(false);

        $chain = new ChainAuditTransport([$t1, $t2]);

        self::assertFalse($chain->supports($this->createContext(AuditPhase::OnFlush)));
    }

    public function testSendOnlyCallsTransportsThatSupportThePhase(): void
    {
        $log = new AuditLog('Class', '1', 'create');
        $context = $this->createContext(AuditPhase::OnFlush, $log);

        $t1 = $this->createMock(AuditTransportInterface::class);
        $t1->method('supports')->with($context)->willReturn(true);
        $t1->expects($this->once())->method('send')->with($context);

        $t2 = $this->createMock(AuditTransportInterface::class);
        $t2->method('supports')->with($context)->willReturn(false);
        $t2->expects($this->never())->method('send');

        $chain = new ChainAuditTransport([$t1, $t2]);
        $chain->send($context);
    }

    public function testSupportsDoesNotExhaustTraversableTransportsBeforeSend(): void
    {
        $log = new AuditLog('Class', '1', 'create');
        $context = $this->createContext(AuditPhase::PostFlush, $log);

        $transport = $this->createMock(AuditTransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('supports')
            ->with($context)
            ->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with($context);

        $chain = new ChainAuditTransport((static function () use ($transport): iterable {
            yield $transport;
        })());

        self::assertTrue($chain->supports($context));
        $chain->send($context);
    }

    public function testSendIsFailFastWhenATransportThrows(): void
    {
        $log = new AuditLog('Class', '1', 'create');
        $context = $this->createContext(AuditPhase::PostFlush, $log);

        $failingTransport = $this->createMock(AuditTransportInterface::class);
        $failingTransport->method('supports')->with($context)->willReturn(true);
        $failingTransport->expects($this->once())
            ->method('send')
            ->willThrowException(new RuntimeException('boom'));

        $laterTransport = $this->createMock(AuditTransportInterface::class);
        $laterTransport->expects($this->never())->method('send');

        $chain = new ChainAuditTransport([$failingTransport, $laterTransport]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $chain->send($context);
    }

    private function createContext(AuditPhase $phase, ?AuditLog $audit = null): AuditTransportContext
    {
        return new AuditTransportContext(
            $phase,
            self::createStub(EntityManagerInterface::class),
            $audit ?? new AuditLog('Class', '1', 'create'),
        );
    }
}
