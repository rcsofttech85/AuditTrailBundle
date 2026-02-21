<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
class AuditDispatcherTest extends TestCase
{
    private AuditTransportInterface&MockObject $transport;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private LoggerInterface&MockObject $logger;

    private EntityManagerInterface&MockObject $em;

    private AuditLog $audit;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(AuditTransportInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->audit = new AuditLog('App\Entity\Post', '123', 'update');
    }

    public function testDispatchSuccess(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->eventDispatcher);
        $this->transport->method('supports')->willReturn(true);
        $this->transport->expects($this->once())->method('send');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchSignsLogWhenIntegrityEnabled(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, null, $this->integrityService);

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->expects($this->once())
            ->method('generateSignature')
            ->with($this->audit)
            ->willReturn('test_signature');

        $this->transport->method('supports')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
        self::assertSame('test_signature', $this->audit->signature);
    }

    public function testDispatchTransportFailureLogsError(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, null, null, $this->logger, false);
        $this->transport->method('supports')->willReturn(true);
        $this->transport->method('send')->willThrowException(new Exception('Transport error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(self::stringContains('Audit transport failed'));

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchTransportFailureWithException(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, null, null, null, true);
        $this->transport->method('supports')->willReturn(true);
        $this->transport->method('send')->willThrowException(new Exception('Transport error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transport error');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }
}
