<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Factory;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use stdClass;
use Symfony\Component\Uid\Factory\MockUuidFactory;
use Symfony\Component\Uid\Factory\UuidFactory;

final class AuditLogMessageFactoryTest extends TestCase
{
    private AuditLogMessageFactory $factory;

    private EntityIdResolverInterface $idResolver;

    protected function setUp(): void
    {
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);
        $this->factory = new AuditLogMessageFactory($this->idResolver, new UuidFactory());
    }

    public function testCreateQueueMessageResolvesEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('42');
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver, new UuidFactory());

        $log = new AuditLog(stdClass::class, null, AuditAction::Create);

        $message = $this->factory->createQueueMessage($this->createContext($log));

        self::assertSame('42', $message->entityId);
        self::assertSame(stdClass::class, $message->entityClass);
        self::assertSame('create', $message->action);
    }

    public function testCreatePersistMessageResolvesEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('42');
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver, new UuidFactory());

        $log = new AuditLog(stdClass::class, null, AuditAction::Create);

        $message = $this->factory->createPersistMessage($this->createContext($log));

        self::assertSame('42', $message->entityId);
        self::assertSame(stdClass::class, $message->entityClass);
        self::assertSame('create', $message->action);
    }

    public function testCreateQueueMessageFallsBackToLogEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn(null);
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver, new UuidFactory());

        $log = new AuditLog(stdClass::class, '100', AuditAction::Update);

        $message = $this->factory->createQueueMessage($this->createContext($log));

        self::assertSame('100', $message->entityId);
    }

    public function testCreatePersistMessageFallsBackToLogEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn(null);
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver, new UuidFactory());

        $log = new AuditLog(stdClass::class, '100', AuditAction::Update);

        $message = $this->factory->createPersistMessage($this->createContext($log));

        self::assertSame('100', $message->entityId);
    }

    public function testCreatePersistMessageIncludesSignature(): void
    {
        $log = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $log->signature = 'test-sig';

        $message = $this->factory->createPersistMessage($this->createContext($log));

        self::assertSame('test-sig', $message->signature);
    }

    public function testCreatePersistMessageInitializesAndReusesTheAuditId(): void
    {
        $this->factory = new AuditLogMessageFactory(
            $this->idResolver,
            $this->createUuidFactory(
                '0195f4d8-b087-7d44-9c4f-a5c6d4aa0001',
                '0195f4d8-b087-7d44-9c4f-a5c6d4aa0002',
                '0195f4d8-b087-7d44-9c4f-a5c6d4aa0003',
            ),
        );
        $log = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $context = $this->createContext($log);

        $firstMessage = $this->factory->createPersistMessage($context);
        $secondMessage = $this->factory->createPersistMessage($context);

        self::assertSame('0195f4d8-b087-7d44-9c4f-a5c6d4aa0001', $firstMessage->auditId);
        self::assertSame('0195f4d8-b087-7d44-9c4f-a5c6d4aa0002', $firstMessage->deliveryId);
        self::assertSame($log->id?->toRfc4122(), $firstMessage->auditId);
        self::assertSame($firstMessage->auditId, $secondMessage->auditId);
        self::assertSame($firstMessage->deliveryId, $secondMessage->deliveryId);
    }

    public function testCreateQueueMessageIncludesSignatureAndDeliveryId(): void
    {
        $log = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $log->signature = 'test-sig';
        $log->deliveryId = 'delivery-123';

        $message = $this->factory->createQueueMessage($this->createContext($log));

        self::assertSame('test-sig', $message->signature);
        self::assertSame('delivery-123', $message->deliveryId);
    }

    public function testCreateQueueMessagePassesContext(): void
    {
        $idResolver = $this->useIdResolverMock();
        $log = new AuditLog(stdClass::class, '1', AuditAction::Create);
        $context = new AuditTransportContext(
            AuditPhase::PostFlush,
            self::createStub(EntityManagerInterface::class),
            $log,
            null,
            new stdClass(),
        );

        $idResolver->expects($this->once())
            ->method('resolve')
            ->with($log, $context)
            ->willReturn('99');

        $message = $this->factory->createQueueMessage($context);

        self::assertSame('99', $message->entityId);
    }

    private function useIdResolverMock(): EntityIdResolverInterface&MockObject
    {
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver, new UuidFactory());

        return $idResolver;
    }

    private function createContext(AuditLog $log): AuditTransportContext
    {
        return new AuditTransportContext(
            AuditPhase::PostFlush,
            self::createStub(EntityManagerInterface::class),
            $log,
        );
    }

    private function createUuidFactory(string ...$uuids): MockUuidFactory
    {
        return new MockUuidFactory($uuids);
    }
}
