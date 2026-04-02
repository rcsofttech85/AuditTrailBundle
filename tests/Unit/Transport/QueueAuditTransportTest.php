<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class QueueAuditTransportTest extends TestCase
{
    private QueueAuditTransport $transport;

    /** @var (MessageBusInterface&\PHPUnit\Framework\MockObject\Stub)|(MessageBusInterface&MockObject) */
    private MessageBusInterface $bus;

    /** @var (EventDispatcherInterface&\PHPUnit\Framework\MockObject\Stub)|(EventDispatcherInterface&MockObject) */
    private EventDispatcherInterface $eventDispatcher;

    /** @var AuditIntegrityServiceInterface&\PHPUnit\Framework\MockObject\Stub */
    private AuditIntegrityServiceInterface $integrityService;

    /** @var AuditLogMessageFactory&\PHPUnit\Framework\MockObject\Stub */
    private AuditLogMessageFactory $messageFactory;

    protected function setUp(): void
    {
        $this->bus = self::createStub(MessageBusInterface::class);
        $this->eventDispatcher = self::createStub(EventDispatcherInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->messageFactory = self::createStub(AuditLogMessageFactory::class);

        $this->resetTransport();
    }

    /** @return MessageBusInterface&MockObject */
    private function useBusMock(): MessageBusInterface
    {
        $bus = self::createMock(MessageBusInterface::class);
        $this->bus = $bus;
        $this->resetTransport();

        return $bus;
    }

    /** @return EventDispatcherInterface&MockObject */
    private function useEventDispatcherMock(): EventDispatcherInterface
    {
        $eventDispatcher = self::createMock(EventDispatcherInterface::class);
        $this->eventDispatcher = $eventDispatcher;
        $this->resetTransport();

        return $eventDispatcher;
    }

    private function resetTransport(): void
    {
        $this->transport = new QueueAuditTransport(
            $this->bus,
            $this->eventDispatcher,
            $this->integrityService,
            $this->messageFactory,
            'test_api_key'
        );
    }

    public function testSendDispatchesMessageWithStamps(): void
    {
        $log = new AuditLog('TestEntity', '1', AuditLogInterface::ACTION_CREATE, new DateTimeImmutable());

        $queueMessage = new AuditLogMessage(
            entityClass: 'TestEntity',
            entityId: '1',
            action: 'create',
            oldValues: null,
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            createdAt: $log->createdAt->format(DateTimeInterface::ATOM),
        );

        $this->messageFactory->method('createQueueMessage')->willReturn($queueMessage);

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('signPayload')->willReturn('test_signature');

        $bus = $this->useBusMock();
        $bus->expects($this->once())
            ->method('dispatch')
            ->with(
                self::isInstanceOf(AuditLogMessage::class),
                self::callback(static function (array $stamps) {
                    $hasApiKeyStamp = false;
                    $hasSignatureStamp = false;
                    foreach ($stamps as $stamp) {
                        if (
                            $stamp instanceof ApiKeyStamp
                            && $stamp->apiKey === 'test_api_key'
                        ) {
                            $hasApiKeyStamp = true;
                        }
                        if (
                            $stamp instanceof SignatureStamp
                            && $stamp->signature === 'test_signature'
                        ) {
                            $hasSignatureStamp = true;
                        }
                    }

                    return $hasApiKeyStamp && $hasSignatureStamp;
                })
            )
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($log);
    }

    public function testSendPropagatesException(): void
    {
        $log = new AuditLog('TestEntity', '1', AuditLogInterface::ACTION_CREATE, new DateTimeImmutable());

        $queueMessage = new AuditLogMessage(
            entityClass: 'TestEntity',
            entityId: '1',
            action: 'create',
            oldValues: null,
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            createdAt: $log->createdAt->format(DateTimeInterface::ATOM),
        );

        $this->messageFactory->method('createQueueMessage')->willReturn($queueMessage);

        $bus = $this->useBusMock();
        $bus->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new Exception('Bus error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bus error');

        $this->transport->send($log);
    }

    public function testSendResolvesPendingId(): void
    {
        $log = new AuditLog('TestEntity', 'pending', AuditLogInterface::ACTION_CREATE);

        $queueMessage = new AuditLogMessage(
            entityClass: 'TestEntity',
            entityId: '123',
            action: 'create',
            oldValues: null,
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            createdAt: $log->createdAt->format(DateTimeInterface::ATOM),
        );

        $this->messageFactory->method('createQueueMessage')->willReturn($queueMessage);

        $bus = $this->useBusMock();
        $bus->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(static function (AuditLogMessage $message) {
                return $message->entityId === '123';
            }))
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($log);
    }

    public function testSendIsCancelledByStoppingPropagation(): void
    {
        $log = new AuditLog('TestEntity', '1', AuditLogInterface::ACTION_CREATE, new DateTimeImmutable());

        $queueMessage = new AuditLogMessage(
            entityClass: 'TestEntity',
            entityId: '1',
            action: 'create',
            oldValues: null,
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            createdAt: $log->createdAt->format(DateTimeInterface::ATOM),
        );

        $this->messageFactory->method('createQueueMessage')->willReturn($queueMessage);

        $eventDispatcher = $this->useEventDispatcherMock();
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (AuditMessageStampEvent $event) {
                $event->stopPropagation();

                return $event;
            });

        $bus = $this->useBusMock();
        $bus->expects($this->never())
            ->method('dispatch');

        $this->transport->send($log);
    }

    public function testSupports(): void
    {
        self::assertTrue($this->transport->supports('post_flush'));
        self::assertFalse($this->transport->supports('pre_flush'));
    }
}
