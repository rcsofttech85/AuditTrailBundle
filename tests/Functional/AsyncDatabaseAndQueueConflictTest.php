<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditLogContextProcessor;
use Rcsofttech\AuditTrailBundle\Service\AuditLogWriter;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Transport\AsyncDatabaseAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AsyncDatabaseAndQueueConflictTest extends AbstractFunctionalTestCase
{
    public function testBothAsyncDatabaseAndQueueTransportsWorkWithoutConflict(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('1');

        $factory = new AuditLogMessageFactory($idResolver);
        $eventDispatcher = new EventDispatcher();
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(false);

        $mockBus = $this->createMock(MessageBusInterface::class);
        $dispatchedMessages = [];

        $mockBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatchedMessages) {
                $dispatchedMessages[] = $message;

                return new Envelope($message);
            });

        $asyncDbTransport = new AsyncDatabaseAuditTransport($mockBus, $factory);
        $queueTransport = new QueueAuditTransport($mockBus, $eventDispatcher, $integrityService, $factory);

        $chainTransport = new ChainAuditTransport([$asyncDbTransport, $queueTransport]);
        $auditDispatcher = new AuditDispatcher(
            $chainTransport,
            new AuditLogContextProcessor(new ContextSanitizer()),
            new AuditLogWriter(),
            null,
            null,
            null,
            false,
            true,
        );
        $log = new AuditLog(TestEntity::class, '1', 'create');
        $log->signature = 'mock-signature-hash';
        $log->context = ['test' => true];

        $auditDispatcher->dispatch($log, $em, AuditPhase::PostFlush);
        self::assertCount(2, $dispatchedMessages, 'Exactly two messages should be dispatched to the bus.');

        $hasPersistMessage = false;
        $hasQueueMessage = false;

        foreach ($dispatchedMessages as $message) {
            if ($message instanceof PersistAuditLogMessage) {
                $hasPersistMessage = true;
                self::assertSame('create', $message->action, 'Persist message should retain the action.');
                self::assertSame('mock-signature-hash', $message->signature, 'Persist message MUST carry the signature to the database.');
                self::assertSame(['test' => true], $message->context, 'Persist message MUST carry context.');
            }
            if ($message instanceof AuditLogMessage) {
                $hasQueueMessage = true;
                self::assertSame('create', $message->action, 'Queue message should retain the action.');
            }
        }

        self::assertTrue($hasPersistMessage, 'A PersistAuditLogMessage must be dispatched to save the data in the DB worker.');
        self::assertTrue($hasQueueMessage, 'An AuditLogMessage must be dispatched to send the data to the external Queue worker.');
    }
}
