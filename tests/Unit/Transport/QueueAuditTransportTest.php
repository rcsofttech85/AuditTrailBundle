<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
class QueueAuditTransportTest extends TestCase
{
    private QueueAuditTransport $transport;

    private MessageBusInterface&MockObject $bus;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private EntityIdResolverInterface&MockObject $idResolver;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);

        $this->transport = new QueueAuditTransport(
            $this->bus,
            $this->eventDispatcher,
            $this->integrityService,
            $this->idResolver,
            'test_api_key'
        );
    }

    public function testSendDispatchesMessageWithStamps(): void
    {
        $log = new AuditLog('TestEntity', '1', AuditLogInterface::ACTION_CREATE, new DateTimeImmutable());

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('signPayload')->willReturn('test_signature');

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with(
                self::isInstanceOf(AuditLogMessage::class),
                self::callback(static function ($stamps) {
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

        $this->bus->method('dispatch')->willThrowException(new Exception('Bus error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bus error');

        $this->transport->send($log);
    }

    public function testSendResolvesPendingId(): void
    {
        $log = new AuditLog('TestEntity', 'pending', AuditLogInterface::ACTION_CREATE);

        // pass context that EntityIdResolver understands.
        $context = ['is_insert' => true];

        $this->idResolver->method('resolve')->willReturn('123');

        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $context = ['entity' => $entity, 'em' => $em];

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(static function (AuditLogMessage $message) {
                return $message->entityId === '123';
            }))
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($log, $context);
    }

    public function testSendIsCancelledByStoppingPropagation(): void
    {
        $log = new AuditLog('TestEntity', '1', AuditLogInterface::ACTION_CREATE, new DateTimeImmutable());

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (AuditMessageStampEvent $event) {
                $event->stopPropagation();

                return $event;
            });

        $this->bus->expects($this->never())
            ->method('dispatch');

        $this->transport->send($log);
    }

    public function testSupports(): void
    {
        self::assertTrue($this->transport->supports('post_flush'));
        self::assertFalse($this->transport->supports('pre_flush'));
    }
}
