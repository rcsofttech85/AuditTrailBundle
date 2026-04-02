<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

final class AuditKernelSubscriberTest extends TestCase
{
    private AuditAccessHandlerInterface&MockObject $accessHandler;

    protected function setUp(): void
    {
        $this->accessHandler = $this->createMock(AuditAccessHandlerInterface::class);
    }

    public function testTerminateReturnsEarlyWhenNoPendingAccesses(): void
    {
        $this->accessHandler->expects($this->once())
            ->method('hasPendingAccesses')
            ->willReturn(false);
        $this->accessHandler->expects($this->never())->method('flushPendingAccesses');

        $subscriber = new AuditKernelSubscriber($this->accessHandler);
        $subscriber->onKernelTerminate($this->createTerminateEvent());
    }

    public function testTerminateFlushesPendingAccessesOnce(): void
    {
        $this->accessHandler->expects($this->once())
            ->method('hasPendingAccesses')
            ->willReturn(true);
        $this->accessHandler->expects($this->once())->method('flushPendingAccesses');

        $subscriber = new AuditKernelSubscriber($this->accessHandler);
        $subscriber->onKernelTerminate($this->createTerminateEvent());
    }

    private function createTerminateEvent(): TerminateEvent
    {
        $kernel = self::createStub(KernelInterface::class);

        return new TerminateEvent($kernel, Request::create('/'), new Response());
    }
}
