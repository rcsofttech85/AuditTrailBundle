<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Factory;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class AuditLogMessageFactoryTest extends TestCase
{
    private AuditLogMessageFactory $factory;

    private EntityIdResolverInterface&MockObject $idResolver;

    protected function setUp(): void
    {
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);
        $this->factory = new AuditLogMessageFactory($this->idResolver);
    }

    public function testCreateQueueMessageResolvesEntityId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $this->idResolver->method('resolve')->willReturn('42');

        $message = $this->factory->createQueueMessage($log);

        self::assertSame('42', $message->entityId);
        self::assertSame(stdClass::class, $message->entityClass);
        self::assertSame('create', $message->action);
    }

    public function testCreatePersistMessageResolvesEntityId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $this->idResolver->method('resolve')->willReturn('42');

        $message = $this->factory->createPersistMessage($log);

        self::assertSame('42', $message->entityId);
        self::assertSame(stdClass::class, $message->entityClass);
        self::assertSame('create', $message->action);
    }

    public function testCreateQueueMessageFallsBackToLogEntityId(): void
    {
        $log = new AuditLog(stdClass::class, '100', AuditLogInterface::ACTION_UPDATE);

        $this->idResolver->method('resolve')->willReturn(null);

        $message = $this->factory->createQueueMessage($log);

        self::assertSame('100', $message->entityId);
    }

    public function testCreatePersistMessageFallsBackToLogEntityId(): void
    {
        $log = new AuditLog(stdClass::class, '100', AuditLogInterface::ACTION_UPDATE);

        $this->idResolver->method('resolve')->willReturn(null);

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
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);
        $context = ['entity' => new stdClass(), 'em' => 'mock'];

        $this->idResolver->expects($this->once())
            ->method('resolve')
            ->with($log, $context)
            ->willReturn('99');

        $message = $this->factory->createQueueMessage($log, $context);

        self::assertSame('99', $message->entityId);
    }
}
