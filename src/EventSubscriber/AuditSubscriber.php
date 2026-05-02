<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditLifecycleState;
use Rcsofttech\AuditTrailBundle\Service\AuditOnFlushProcessor;
use Rcsofttech\AuditTrailBundle\Service\AuditPostFlushProcessor;
use Symfony\Contracts\Service\ResetInterface;

#[AsDoctrineListener(event: Events::onFlush, priority: self::RUN_AFTER_EXTENSION_LISTENERS_PRIORITY)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    private const int RUN_AFTER_EXTENSION_LISTENERS_PRIORITY = -1000;

    public function __construct(
        private readonly ScheduledAuditManagerInterface $auditManager,
        private readonly AuditAccessHandlerInterface $accessHandler,
        private readonly AuditLifecycleState $lifecycleState,
        private readonly AuditOnFlushProcessor $onFlushProcessor,
        private readonly AuditPostFlushProcessor $postFlushProcessor,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->auditManager->isEnabled();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->lifecycleState->canProcessOnFlush($this->auditManager->isEnabled())) {
            return;
        }

        $this->lifecycleState->beginOnFlush();

        try {
            $this->onFlushProcessor->process($args->getObjectManager());
        } finally {
            $this->lifecycleState->endOnFlush();
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        if (!$this->lifecycleState->canProcessPostLoad($this->auditManager->isEnabled())) {
            return;
        }

        $this->accessHandler->handleAccess($args->getObject(), $args->getObjectManager());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->lifecycleState->canProcessPostFlush($this->auditManager->isEnabled())) {
            return;
        }

        $this->lifecycleState->beginPostFlush();

        try {
            $this->postFlushProcessor->process($args->getObjectManager());
        } finally {
            $this->lifecycleState->endPostFlush();
        }
    }

    public function onClear(): void
    {
        $this->auditManager->clear();
        $this->lifecycleState->reset();
        $this->accessHandler->reset();
    }

    public function reset(): void
    {
        $this->auditManager->reset();
        $this->lifecycleState->reset();
        $this->accessHandler->reset();
    }
}
