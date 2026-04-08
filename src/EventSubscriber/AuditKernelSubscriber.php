<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final class AuditKernelSubscriber
{
    public function __construct(
        private AuditAccessHandlerInterface $accessHandler,
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->accessHandler->hasPendingAccesses()) {
            return;
        }

        $this->accessHandler->flushPendingAccesses();
    }
}
