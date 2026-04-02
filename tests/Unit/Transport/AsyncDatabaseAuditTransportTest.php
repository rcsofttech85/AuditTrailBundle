<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\AsyncDatabaseAuditTransport;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AsyncDatabaseAuditTransportTest extends TestCase
{
    private AsyncDatabaseAuditTransport $transport;

    private MessageBusInterface $bus;

    private AuditLogMessageFactory $messageFactory;

    protected function setUp(): void
    {
        $this->bus = self::createStub(MessageBusInterface::class);
        $this->messageFactory = self::createStub(AuditLogMessageFactory::class);

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
            ->with($log, [])
            ->willReturn($persistMessage);

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($persistMessage)
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($log);
    }

    public function testSendPassesContextToFactory(): void
    {
        [$bus, $messageFactory] = $this->useTransportMocks();
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_UPDATE);
        $context = ['phase' => 'post_flush', 'em' => 'mock'];

        $persistMessage = self::createStub(PersistAuditLogMessage::class);

        $messageFactory->expects($this->once())
            ->method('createPersistMessage')
            ->with($log, $context)
            ->willReturn($persistMessage);

        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($log, $context);
    }

    public function testSupportsPostFlushAndPostLoad(): void
    {
        self::assertTrue($this->transport->supports('post_flush'));
        self::assertTrue($this->transport->supports('post_load'));
    }

    public function testDoesNotSupportOnFlush(): void
    {
        self::assertFalse($this->transport->supports('on_flush'));
        self::assertFalse($this->transport->supports('pre_flush'));
    }

    /**
     * @return array{0: MessageBusInterface&MockObject, 1: AuditLogMessageFactory&MockObject}
     */
    private function useTransportMocks(): array
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $messageFactory = $this->createMock(AuditLogMessageFactory::class);
        $this->bus = $bus;
        $this->messageFactory = $messageFactory;
        $this->transport = new AsyncDatabaseAuditTransport($bus, $messageFactory);

        return [$bus, $messageFactory];
    }
}
