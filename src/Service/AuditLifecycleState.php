<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

final class AuditLifecycleState
{
    private bool $onFlushProcessing = false;

    private int $postFlushDepth = 0;

    public function canProcessOnFlush(bool $enabled): bool
    {
        return $enabled && !$this->onFlushProcessing && $this->postFlushDepth === 0;
    }

    public function beginOnFlush(): void
    {
        $this->onFlushProcessing = true;
    }

    public function endOnFlush(): void
    {
        $this->onFlushProcessing = false;
    }

    public function canProcessPostLoad(bool $enabled): bool
    {
        return $enabled && !$this->onFlushProcessing && $this->postFlushDepth === 0;
    }

    public function canProcessPostFlush(bool $enabled): bool
    {
        return $enabled && $this->postFlushDepth === 0;
    }

    public function beginPostFlush(): void
    {
        ++$this->postFlushDepth;
    }

    public function endPostFlush(): void
    {
        if ($this->postFlushDepth > 0) {
            --$this->postFlushDepth;
        }
    }

    public function reset(): void
    {
        $this->onFlushProcessing = false;
        $this->postFlushDepth = 0;
    }
}
