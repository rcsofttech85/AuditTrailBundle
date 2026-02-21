<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Event;

use Psr\EventDispatcher\StoppableEventInterface;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class AuditMessageStampEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    /**
     * @param array<StampInterface> $stamps
     */
    public function __construct(
        public readonly AuditLogMessage $message,
        private array $stamps = [],
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

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
