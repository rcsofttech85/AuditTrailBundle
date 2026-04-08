<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogMessageFactoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\AsyncDatabaseAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AsyncDatabaseAuditTransportTest extends TestCase
{
    private AsyncDatabaseAuditTransport $transport;

    private MessageBusInterface $bus;

    private AuditLogMessageFactoryInterface $messageFactory;

    protected function setUp(): void
    {
        $this->bus = self::createStub(MessageBusInterface::class);
        $this->messageFactory = self::createStub(AuditLogMessageFactoryInterface::class);

        $this->transport = new AsyncDatabaseAuditTransport(
            $this->bus,
            $this->messageFactory,
        );
    }

    public function testSendDispatchesPersistMessage(): void
    {
        [$bus, $messageFactory] = $this->useTransportMocks();
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);

        $persistMessage = self::createStub(PersistAuditLogMessage::class);

        $messageFactory->expects($this->once())
            ->method('createPersistMessage')
            ->with($this->createContext(AuditPhase::PostFlush, $log))
            ->willReturn($persistMessage);

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($persistMessage)
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($this->createContext(AuditPhase::PostFlush, $log));
    }

    public function testSendPassesContextToFactory(): void
    {
        [$bus, $messageFactory] = $this->useTransportMocks();
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $context = $this->createContext(AuditPhase::PostFlush, $log);

        $persistMessage = self::createStub(PersistAuditLogMessage::class);

        $messageFactory->expects($this->once())
            ->method('createPersistMessage')
            ->with($context)
            ->willReturn($persistMessage);

        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($context);
    }

    public function testSupportsPostFlushAndPostLoad(): void
    {
        self::assertTrue($this->transport->supports($this->createContext(AuditPhase::PostFlush)));
        self::assertTrue($this->transport->supports($this->createContext(AuditPhase::PostLoad)));
    }

    public function testDoesNotSupportOnFlush(): void
    {
        self::assertFalse($this->transport->supports($this->createContext(AuditPhase::OnFlush)));
        self::assertFalse($this->transport->supports($this->createContext(AuditPhase::ManualFlush)));
    }

    /**
     * @return array{0: MessageBusInterface&MockObject, 1: AuditLogMessageFactoryInterface&MockObject}
     */
    private function useTransportMocks(): array
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $messageFactory = $this->createMock(AuditLogMessageFactoryInterface::class);
        $this->bus = $bus;
        $this->messageFactory = $messageFactory;
        $this->transport = new AsyncDatabaseAuditTransport($bus, $messageFactory);

        return [$bus, $messageFactory];
    }

    private function createContext(AuditPhase $phase, ?AuditLog $log = null): AuditTransportContext
    {
        return new AuditTransportContext(
            $phase,
            self::createStub(EntityManagerInterface::class),
            $log ?? new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE),
        );
    }
}
