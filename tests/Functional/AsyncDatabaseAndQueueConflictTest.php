<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Transport\AsyncDatabaseAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class AsyncDatabaseAndQueueConflictTest extends AbstractFunctionalTestCase
{
    public function testBothAsyncDatabaseAndQueueTransportsWorkWithoutConflict(): void
    {
        // Manually instantiate dependencies to prove pipeline logic without container overhead
        $em = $this->createMock(EntityManagerInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('1');

        $factory = new AuditLogMessageFactory($idResolver);
        $eventDispatcher = new EventDispatcher(); // Simple dispatcher for testing
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(false);

        // mock the MessageBus to intercept all dispatches globally.
        $mockBus = $this->createMock(MessageBusInterface::class);
        $dispatchedMessages = [];

        // expect the bus to be called EXACTLY 2 times.
        // Once with PersistAuditLogMessage, once with AuditLogMessage.
        $mockBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function ($message) use (&$dispatchedMessages) {
                $dispatchedMessages[] = $message;

                return new Envelope($message ?? current($dispatchedMessages)); // Fallback
            });

        // Instantiate both transports identically to how the bundle DI extension wires them
        $asyncDbTransport = new AsyncDatabaseAuditTransport($mockBus, $factory);
        $queueTransport = new QueueAuditTransport($mockBus, $eventDispatcher, $integrityService, $factory);

        // Combine them into the ChainAuditTransport, just like `AuditTrailExtension` does
        $chainTransport = new ChainAuditTransport([$asyncDbTransport, $queueTransport]);

        // Build the dispatcher
        $auditDispatcher = new AuditDispatcher($chainTransport);

        // Act: Create an AuditLog and dispatch it through the pipeline
        $log = new AuditLog(TestEntity::class, '1', 'create');
        $log->signature = 'mock-signature-hash';
        $log->context = ['test' => true];

        // use 'post_flush' because AsyncDatabaseTransport specifically listens to post_flush
        $auditDispatcher->dispatch($log, $em, 'post_flush');

        // Verify exactly 2 distinct messages were dispatched
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
                // AuditLogMessage does not have a signature property, it relies on stamps (verified in Unit tests)
            }
        }

        // Each transport fired exactly its intended DTO without interfering or doubling up.
        self::assertTrue($hasPersistMessage, 'A PersistAuditLogMessage must be dispatched to save the data in the DB worker.');
        self::assertTrue($hasQueueMessage, 'An AuditLogMessage must be dispatched to send the data to the external Queue worker.');
    }
}
