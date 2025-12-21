<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class QueueAuditTransportTest extends TestCase
{
    private QueueAuditTransport $transport;
    private MessageBusInterface $bus;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
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
            ->with($this->callback(function (AuditLogMessage $message) {
                $this->assertSame('1', $message->entityId);
                $this->assertSame('TestEntity', $message->entityClass);
                $this->assertSame('create', $message->action);
                return true;
            }))
            ->willReturnCallback(fn($message) => new Envelope($message));

        $this->transport->send($log, ['phase' => 'post_flush']);
    }
}
