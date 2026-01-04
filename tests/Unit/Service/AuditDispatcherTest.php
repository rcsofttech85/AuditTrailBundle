<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;

#[AllowMockObjectsWithoutExpectations]
class AuditDispatcherTest extends TestCase
{
    private AuditTransportInterface&MockObject $transport;
    private AuditIntegrityServiceInterface&MockObject $integrityService;
    private LoggerInterface&MockObject $logger;
    private EntityManagerInterface&MockObject $em;
    private AuditLogInterface&MockObject $audit;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(AuditTransportInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->audit = $this->createMock(AuditLogInterface::class);
    }

    public function testDispatchSuccess(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService);
        $this->transport->expects($this->once())->method('send');

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testDispatchSignsLogWhenIntegrityEnabled(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService);

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->expects($this->once())
            ->method('generateSignature')
            ->with($this->audit)
            ->willReturn('test_signature');

        $this->audit->expects($this->once())
            ->method('setSignature')
            ->with('test_signature');

        $this->transport->expects($this->once())->method('send');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchTransportFailureWithFallback(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService, $this->logger, false, true);
        $this->transport->method('send')->willThrowException(new \Exception('Transport error'));

        $this->logger->expects($this->once())->method('error');
        $this->em->method('isOpen')->willReturn(true);
        $this->em->method('contains')->willReturn(false);
        $this->em->expects($this->once())->method('persist')->with($this->audit);

        self::assertFalse($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testDispatchTransportFailureWithException(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService, null, true, true);
        $this->transport->method('send')->willThrowException(new \Exception('Transport error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Transport error');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchTransportFailureNoFallback(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService, null, false, false);
        $this->transport->method('send')->willThrowException(new \Exception('Transport error'));

        $this->em->expects($this->never())->method('persist');

        self::assertFalse($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testPersistFallbackOnFlushComputesChangeSet(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService, null, false, true);
        $this->transport->method('send')->willThrowException(new \Exception('Transport error'));

        $uow = $this->createMock(UnitOfWork::class);
        $this->em->method('isOpen')->willReturn(true);
        $this->em->method('getUnitOfWork')->willReturn($uow);
        $this->em->method('getClassMetadata')
            ->willReturn($this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class));

        $uow->expects($this->once())->method('computeChangeSet');

        $dispatcher->dispatch($this->audit, $this->em, 'on_flush', $uow);
    }

    public function testPersistFallbackEmClosed(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService, null, false, true);
        $this->transport->method('send')->willThrowException(new \Exception('Transport error'));

        $this->em->method('isOpen')->willReturn(false);
        $this->em->expects($this->never())->method('persist');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testPersistFallbackAlreadyContainsAudit(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService, null, false, true);
        $this->transport->method('send')->willThrowException(new \Exception('Transport error'));

        $this->em->method('isOpen')->willReturn(true);
        $this->em->method('contains')->with($this->audit)->willReturn(true);
        $this->em->expects($this->never())->method('persist');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testSupports(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->integrityService);
        $this->transport->method('supports')->willReturn(true);

        self::assertTrue($dispatcher->supports('post_flush'));
    }
}
