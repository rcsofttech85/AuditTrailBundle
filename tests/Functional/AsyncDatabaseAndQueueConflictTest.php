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
use Rcsofttech\AuditTrailBundle\Service\AuditContextNormalizer;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditFallbackPersister;
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
use Symfony\Component\Uid\Factory\UuidFactory;

final class AsyncDatabaseAndQueueConflictTest extends AbstractFunctionalTestCase
{
    public function testBothAsyncDatabaseAndQueueTransportsWorkWithoutConflict(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('1');
        $uuidFactory = self::getContainer()->get(UuidFactory::class);
        self::assertInstanceOf(UuidFactory::class, $uuidFactory);

        $factory = new AuditLogMessageFactory($idResolver, $uuidFactory);
        $eventDispatcher = new EventDispatcher();
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(false);

        $spyBus = new class implements MessageBusInterface {
            public int $dispatchCount = 0;

            public ?PersistAuditLogMessage $persistMessage = null;

            public ?AuditLogMessage $queueMessage = null;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->dispatchCount;

                if ($message instanceof PersistAuditLogMessage) {
                    $this->persistMessage = $message;
                } elseif ($message instanceof AuditLogMessage) {
                    $this->queueMessage = $message;
                }

                return new Envelope($message);
            }
        };

        $asyncDbTransport = new AsyncDatabaseAuditTransport($spyBus, $factory);
        $queueTransport = new QueueAuditTransport($spyBus, $eventDispatcher, $integrityService, $factory);

        $chainTransport = new ChainAuditTransport([$asyncDbTransport, $queueTransport]);
        $auditDispatcher = new AuditDispatcher(
            $chainTransport,
            new AuditLogContextProcessor(new ContextSanitizer(), new AuditContextNormalizer(new ContextSanitizer())),
            new AuditFallbackPersister(new AuditLogWriter($uuidFactory)),
            $uuidFactory,
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
        self::assertSame(2, $spyBus->dispatchCount, 'Exactly two messages should be dispatched to the bus.');
        self::assertInstanceOf(PersistAuditLogMessage::class, $spyBus->persistMessage, 'A PersistAuditLogMessage must be dispatched to save the data in the DB worker.');
        self::assertInstanceOf(AuditLogMessage::class, $spyBus->queueMessage, 'An AuditLogMessage must be dispatched to send the data to the external Queue worker.');

        self::assertSame('create', $spyBus->persistMessage->action, 'Persist message should retain the action.');
        self::assertSame($log->id?->toRfc4122(), $spyBus->persistMessage->auditId, 'Persist message should preserve the original audit UUID.');
        self::assertSame('mock-signature-hash', $spyBus->persistMessage->signature, 'Persist message MUST carry the signature to the database.');
        self::assertSame(['test' => true], $spyBus->persistMessage->context, 'Persist message MUST carry context.');
        self::assertSame('create', $spyBus->queueMessage->action, 'Queue message should retain the action.');
    }
}
