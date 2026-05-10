<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Throwable;

final readonly class AuditDeliveryResult
{
    /**
     * @param list<string> $completedTransports
     */
    private function __construct(
        public bool $delivered,
        public array $completedTransports = [],
        public ?Throwable $failure = null,
    ) {
    }

    public static function delivered(): self
    {
        return new self(true);
    }

    /**
     * @param list<string> $completedTransports
     */
    public static function partiallyDelivered(array $completedTransports, Throwable $failure): self
    {
        return new self(true, $completedTransports, $failure);
    }

    public function isPartial(): bool
    {
        return $this->failure !== null && $this->completedTransports !== [];
    }
}
