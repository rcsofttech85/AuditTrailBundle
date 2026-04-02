<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\MessageHandler;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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
        $message = new PersistAuditLogMessage(
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
            createdAt: '2025-01-01T12:00:00+00:00',
            signature: 'sig-hash',
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa1111',
            context: ['source' => 'test'],
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['deliveryId' => '0195f4d8-b087-7d44-9c4f-a5c6d4aa1111'])
            ->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(AuditLog::class)->willReturn($repository);
        $this->registry->expects($this->once())->method('getManagerForClass')->with(AuditLog::class)->willReturn($em);

        $em->expects($this->once())
            ->method('persist')
            ->with(self::callback(static function (AuditLog $log) {
                return $log->entityClass === 'App\Entity\Product'
                    && $log->entityId === '42'
                    && $log->action === 'create'
                    && $log->oldValues === null
                    && $log->newValues === ['name' => 'Widget']
                    && $log->changedFields === ['name']
                    && $log->userId === 'user-1'
                    && $log->username === 'admin'
                    && $log->ipAddress === '127.0.0.1'
                    && $log->userAgent === 'Mozilla/5.0'
                    && $log->transactionHash === 'abc123'
                    && $log->signature === 'sig-hash'
                    && $log->deliveryId === '0195f4d8-b087-7d44-9c4f-a5c6d4aa1111'
                    && $log->context === ['source' => 'test'];
            }));

        $em->expects($this->once())->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeWithMinimalMessage(): void
    {
        $message = new PersistAuditLogMessage(
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
            createdAt: '2025-06-15T08:00:00+00:00',
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('findOneBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(AuditLog::class)->willReturn($repository);
        $this->registry->expects($this->once())->method('getManagerForClass')->with(AuditLog::class)->willReturn($em);

        $em->expects($this->once())
            ->method('persist')
            ->with(self::callback(static function (AuditLog $log) {
                return $log->entityClass === 'App\Entity\Order'
                    && $log->entityId === '99'
                    && $log->action === 'delete'
                    && $log->userId === null
                    && $log->signature === null
                    && $log->context === [];
            }));

        $em->expects($this->once())->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeSkipsDuplicateDeliveryId(): void
    {
        $message = new PersistAuditLogMessage(
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
            createdAt: '2025-06-15T08:00:00+00:00',
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa2222',
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['deliveryId' => '0195f4d8-b087-7d44-9c4f-a5c6d4aa2222'])
            ->willReturn(new AuditLog('App\Entity\Order', '99', 'delete'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(AuditLog::class)->willReturn($repository);
        $this->registry->expects($this->once())->method('getManagerForClass')->with(AuditLog::class)->willReturn($em);

        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeTreatsUniqueConstraintViolationAsIdempotentSuccess(): void
    {
        $message = new PersistAuditLogMessage(
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
            createdAt: '2025-06-15T08:00:00+00:00',
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa3333',
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['deliveryId' => '0195f4d8-b087-7d44-9c4f-a5c6d4aa3333'])
            ->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(AuditLog::class)->willReturn($repository);
        $this->registry->expects($this->once())->method('getManagerForClass')->with(AuditLog::class)->willReturn($em);

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
        $message = new PersistAuditLogMessage(
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
            createdAt: '2025-06-15T08:00:00+00:00',
            deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa4444',
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['deliveryId' => '0195f4d8-b087-7d44-9c4f-a5c6d4aa4444'])
            ->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(AuditLog::class)->willReturn($repository);
        $this->registry->expects($this->once())->method('getManagerForClass')->with(AuditLog::class)->willReturn($em);

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

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No EntityManager is configured');

        ($this->handler)(new PersistAuditLogMessage('App\Entity\Order', '1', 'create', null, null, null, null, null, null, null, null, '2025-01-01T00:00:00+00:00'));
    }
}
