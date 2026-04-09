<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\MessageHandler;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\MessageHandler\PersistAuditLogHandler;

final class PersistAuditLogHandlerTest extends TestCase
{
    private PersistAuditLogHandler $handler;

    private ManagerRegistry&MockObject $registry;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->handler = new PersistAuditLogHandler($this->registry);
    }

    public function testInvokePersistsAuditLog(): void
    {
        $message = $this->createMessage(
            entityClass: 'App\Entity\Product',
            entityId: '42',
            action: 'create',
            oldValues: null,
            newValues: ['name' => 'Widget'],
            changedFields: ['name'],
            userId: 'user-1',
            username: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla/5.0',
            transactionHash: 'abc123',
            signature: 'sig-hash',
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa1111',
            context: ['source' => 'test'],
        );
        $em = $this->expectEntityManager();
        $persistedLog = null;

        $em->expects($this->once())
            ->method('persist')
            ->with(self::callback(static function (AuditLog $log) use (&$persistedLog): bool {
                $persistedLog = $log;

                return true;
            }));

        $em->expects($this->once())->method('flush');

        ($this->handler)($message);

        $persistedLog = $this->assertPersistedAuditLog($persistedLog);
        self::assertSame('App\Entity\Product', $persistedLog->entityClass);
        self::assertSame('42', $persistedLog->entityId);
        self::assertSame('create', $persistedLog->action);
        self::assertNull($persistedLog->oldValues);
        self::assertSame(['name' => 'Widget'], $persistedLog->newValues);
        self::assertSame(['name'], $persistedLog->changedFields);
        self::assertSame('user-1', $persistedLog->userId);
        self::assertSame('admin', $persistedLog->username);
        self::assertSame('127.0.0.1', $persistedLog->ipAddress);
        self::assertSame('Mozilla/5.0', $persistedLog->userAgent);
        self::assertSame('abc123', $persistedLog->transactionHash);
        self::assertSame('sig-hash', $persistedLog->signature);
        self::assertSame('0195f4d8-b087-7d44-9c4f-a5c6d4aa1111', $persistedLog->deliveryId);
        self::assertSame(['source' => 'test'], $persistedLog->context);
    }

    public function testInvokeWithMinimalMessage(): void
    {
        $message = $this->createMessage(
            entityClass: 'App\Entity\Order',
            entityId: '99',
            action: 'delete',
            oldValues: ['status' => 'active'],
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            signature: null,
            deliveryId: null,
            context: [],
            createdAt: '2025-06-15T08:00:00+00:00',
        );
        $em = $this->expectEntityManager();
        $persistedLog = null;

        $em->expects($this->once())
            ->method('persist')
            ->with(self::callback(static function (AuditLog $log) use (&$persistedLog): bool {
                $persistedLog = $log;

                return true;
            }));

        $em->expects($this->once())->method('flush');

        ($this->handler)($message);

        $persistedLog = $this->assertPersistedAuditLog($persistedLog);
        self::assertSame('App\Entity\Order', $persistedLog->entityClass);
        self::assertSame('99', $persistedLog->entityId);
        self::assertSame('delete', $persistedLog->action);
        self::assertSame(['status' => 'active'], $persistedLog->oldValues);
        self::assertNull($persistedLog->newValues);
        self::assertNull($persistedLog->changedFields);
        self::assertNull($persistedLog->userId);
        self::assertNull($persistedLog->signature);
        self::assertSame([], $persistedLog->context);
    }

    public function testInvokeTreatsUniqueConstraintViolationAsIdempotentSuccess(): void
    {
        $message = $this->createMessage(
            entityClass: 'App\Entity\Order',
            entityId: '99',
            action: 'delete',
            oldValues: ['status' => 'active'],
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa3333',
            createdAt: '2025-06-15T08:00:00+00:00',
        );
        $em = $this->expectEntityManager();

        $em->expects($this->once())->method('persist');
        $duplicateException = self::createStub(UniqueConstraintViolationException::class);
        $em->expects($this->once())
            ->method('flush')
            ->willThrowException($duplicateException);
        $em->expects($this->once())->method('isOpen')->willReturn(true);
        $em->expects($this->once())->method('clear');
        $this->registry->expects($this->never())->method('resetManager');

        ($this->handler)($message);
    }

    public function testInvokeResetsManagerWhenDuplicateClosesEntityManager(): void
    {
        $message = $this->createMessage(
            entityClass: 'App\Entity\Order',
            entityId: '99',
            action: 'delete',
            oldValues: ['status' => 'active'],
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa4444',
            createdAt: '2025-06-15T08:00:00+00:00',
        );
        $em = $this->expectEntityManager();

        $duplicateException = self::createStub(UniqueConstraintViolationException::class);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush')->willThrowException($duplicateException);
        $em->expects($this->once())->method('isOpen')->willReturn(false);
        $em->expects($this->never())->method('clear');
        $this->registry->expects($this->once())->method('resetManager');

        ($this->handler)($message);
    }

    public function testInvokeFailsWhenNoEntityManagerIsConfigured(): void
    {
        $this->registry->expects($this->once())->method('getManagerForClass')->with(AuditLog::class)->willReturn(null);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('No EntityManager is configured');

        ($this->handler)($this->createMessage(entityId: '1'));
    }

    private function expectEntityManager(): EntityManagerInterface&MockObject
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $this->registry->expects($this->once())
            ->method('getManagerForClass')
            ->with(AuditLog::class)
            ->willReturn($em);

        return $em;
    }

    private function assertPersistedAuditLog(?AuditLog $persistedLog): AuditLog
    {
        self::assertNotNull($persistedLog);

        return $persistedLog;
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<int, string>|null   $changedFields
     * @param array<string, mixed>      $context
     */
    private function createMessage(
        string $entityClass = 'App\Entity\Order',
        string $entityId = '99',
        string $action = 'delete',
        ?array $oldValues = ['status' => 'active'],
        ?array $newValues = null,
        ?array $changedFields = null,
        ?string $userId = null,
        ?string $username = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $transactionHash = null,
        string $createdAt = '2025-01-01T12:00:00+00:00',
        ?string $signature = null,
        ?string $deliveryId = null,
        array $context = [],
    ): PersistAuditLogMessage {
        return new PersistAuditLogMessage(
            entityClass: $entityClass,
            entityId: $entityId,
            action: $action,
            oldValues: $oldValues,
            newValues: $newValues,
            changedFields: $changedFields,
            userId: $userId,
            username: $username,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            transactionHash: $transactionHash,
            createdAt: $createdAt,
            signature: $signature,
            deliveryId: $deliveryId,
            context: $context,
        );
    }
}
