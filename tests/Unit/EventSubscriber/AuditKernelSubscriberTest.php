<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

final class AuditKernelSubscriberTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    /** @var UnitOfWork&\PHPUnit\Framework\MockObject\Stub */
    private UnitOfWork $uow;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\Stub */
    private LoggerInterface $logger;

    private AuditAccessHandler&MockObject $accessHandler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->uow = self::createStub(UnitOfWork::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->accessHandler = $this->createMock(AuditAccessHandler::class);
    }

    public function testTerminateReturnsEarlyWhenNoPendingAccesses(): void
    {
        $this->accessHandler->expects($this->once())
            ->method('hasPendingAccesses')
            ->willReturn(false);
        $this->accessHandler->expects($this->never())->method('flushPendingAccesses');
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('getUnitOfWork')->willReturn($this->uow);
        $this->uow->method('getScheduledEntityInsertions')->willReturn([]);
        $this->uow->method('getScheduledEntityUpdates')->willReturn([]);
        $this->uow->method('getScheduledEntityDeletions')->willReturn([]);
        $this->entityManager->expects($this->never())->method('flush');

        $subscriber = new AuditKernelSubscriber($this->entityManager, $this->accessHandler, $this->logger);
        $subscriber->onKernelTerminate($this->createTerminateEvent());
    }

    public function testLogsCriticalWhenTerminateFlushFails(): void
    {
        $auditLog = new AuditLog('App\Entity\User', '1', 'access');
        $logger = $this->createMock(LoggerInterface::class);

        $this->accessHandler->method('hasPendingAccesses')->willReturn(true);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('getUnitOfWork')->willReturn($this->uow);
        $this->uow->method('getScheduledEntityInsertions')->willReturn([$auditLog]);
        $this->uow->method('getScheduledEntityUpdates')->willReturn([]);
        $this->uow->method('getScheduledEntityDeletions')->willReturn([]);

        $this->entityManager->expects($this->once())
            ->method('flush')
            ->willThrowException(new Exception('flush failed'));

        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Failed to flush deferred audit logs during kernel terminate.',
                self::callback(static fn (array $context): bool => ($context['scheduled_insertions'] ?? null) === 1 && $context['exception'] instanceof Exception)
            );

        $this->accessHandler->expects($this->once())->method('flushPendingAccesses');

        $subscriber = new AuditKernelSubscriber($this->entityManager, $this->accessHandler, $logger);
        $subscriber->onKernelTerminate($this->createTerminateEvent());
    }

    private function createTerminateEvent(): TerminateEvent
    {
        $kernel = self::createStub(KernelInterface::class);

        return new TerminateEvent($kernel, Request::create('/'), new Response());
    }
}
