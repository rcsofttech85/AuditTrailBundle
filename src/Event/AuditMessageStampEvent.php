<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class AuditMessageStampEvent
{
    /**
     * @param array<StampInterface> $stamps
     */
    public function __construct(
        public readonly AuditLogMessage $message,
        private array $stamps = [],
        private bool $cancelled = false,
    ) {
    }

    public function addStamp(StampInterface $stamp): void
    {
        $this->stamps[] = $stamp;
    }

    /**
     * @return array<StampInterface>
     */
    public function getStamps(): array
    {
        return $this->stamps;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
