<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use PHPUnit\Framework\MockObject\MockObject;

class QueueAuditTransportTest extends TestCase
{
    private QueueAuditTransport $transport;
    private MessageBusInterface&MockObject $bus;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->transport = new QueueAuditTransport($this->bus, $this->logger);
    }

    public function testSendPostFlushDispatchesMessage(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('1');
        $log->setAction('create');
        $log->setCreatedAt(new \DateTimeImmutable());

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(function (AuditLogMessage $message) {
                self::assertSame('1', $message->entityId);
                self::assertSame('TestEntity', $message->entityClass);
                self::assertSame('create', $message->action);

                return true;
            }))
            ->willReturnCallback(fn ($message) => new Envelope($message));

        $this->transport->send($log, ['phase' => 'post_flush']);
    }
}
