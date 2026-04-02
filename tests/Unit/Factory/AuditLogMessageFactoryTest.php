<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Factory;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use stdClass;

final class AuditLogMessageFactoryTest extends TestCase
{
    private AuditLogMessageFactory $factory;

    private EntityIdResolverInterface $idResolver;

    protected function setUp(): void
    {
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);
        $this->factory = new AuditLogMessageFactory($this->idResolver);
    }

    public function testCreateQueueMessageResolvesEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('42');
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver);

        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $message = $this->factory->createQueueMessage($log);

        self::assertSame('42', $message->entityId);
        self::assertSame(stdClass::class, $message->entityClass);
        self::assertSame('create', $message->action);
    }

    public function testCreatePersistMessageResolvesEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn('42');
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver);

        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $message = $this->factory->createPersistMessage($log);

        self::assertSame('42', $message->entityId);
        self::assertSame(stdClass::class, $message->entityClass);
        self::assertSame('create', $message->action);
    }

    public function testCreateQueueMessageFallsBackToLogEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn(null);
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver);

        $log = new AuditLog(stdClass::class, '100', AuditLogInterface::ACTION_UPDATE);

        $message = $this->factory->createQueueMessage($log);

        self::assertSame('100', $message->entityId);
    }

    public function testCreatePersistMessageFallsBackToLogEntityId(): void
    {
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn(null);
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver);

        $log = new AuditLog(stdClass::class, '100', AuditLogInterface::ACTION_UPDATE);

        $message = $this->factory->createPersistMessage($log);

        self::assertSame('100', $message->entityId);
    }

    public function testCreatePersistMessageIncludesSignature(): void
    {
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);
        $log->signature = 'test-sig';

        $message = $this->factory->createPersistMessage($log);

        self::assertSame('test-sig', $message->signature);
    }

    public function testCreateQueueMessagePassesContext(): void
    {
        $idResolver = $this->useIdResolverMock();
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);
        $context = ['entity' => new stdClass(), 'em' => 'mock'];

        $idResolver->expects($this->once())
            ->method('resolve')
            ->with($log, $context)
            ->willReturn('99');

        $message = $this->factory->createQueueMessage($log, $context);

        self::assertSame('99', $message->entityId);
    }

    private function useIdResolverMock(): EntityIdResolverInterface&MockObject
    {
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $this->idResolver = $idResolver;
        $this->factory = new AuditLogMessageFactory($idResolver);

        return $idResolver;
    }
}
