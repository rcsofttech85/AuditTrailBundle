<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
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

    private LoggerInterface&MockObject $logger;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);

        $this->transport = new QueueAuditTransport(
            $this->bus,
            $this->logger,
            $this->eventDispatcher,
            $this->integrityService,
            'test_api_key'
        );
    }

    public function testSendDispatchesMessageWithStamps(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_CREATE);
        $log->setCreatedAt(new DateTimeImmutable());

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('signPayload')->willReturn('test_signature');

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with(
                self::isInstanceOf(AuditLogMessage::class),
                self::callback(function ($stamps) {
                    $hasApiKeyStamp = false;
                    $hasSignatureStamp = false;
                    foreach ($stamps as $stamp) {
                        if (
                            $stamp instanceof \Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp
                            && $stamp->apiKey === 'test_api_key'
                        ) {
                            $hasApiKeyStamp = true;
                        }
                        if (
                            $stamp instanceof \Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp
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

    public function testSendHandlesException(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_CREATE);
        $log->setCreatedAt(new DateTimeImmutable());

        $this->bus->method('dispatch')->willThrowException(new Exception('Bus error'));
        $this->logger->expects($this->once())->method('error');

        $this->transport->send($log);
    }

    public function testSendResolvesPendingId(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('pending');
        $log->setAction(AuditLogInterface::ACTION_CREATE);

        // we need to pass context that EntityIdResolver understands.
        $context = ['is_insert' => true];

        $entity = new stdClass();
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $metadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->getIdentifierValues($entity);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 123]);

        $context = ['entity' => $entity, 'em' => $em];

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(function (AuditLogMessage $message) {
                return $message->entityId === '123';
            }))
            ->willReturn(new Envelope(new stdClass()));

        $this->transport->send($log, $context);
    }

    public function testSupports(): void
    {
        self::assertTrue($this->transport->supports('post_flush'));
        self::assertFalse($this->transport->supports('pre_flush'));
    }
}
