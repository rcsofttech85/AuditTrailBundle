<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Traversable;

use function is_string;

final class ChainAuditTransport implements AuditTransportInterface
{
    /**
     * @param iterable<AuditTransportInterface> $transports
     */
    public function __construct(
        private readonly iterable $transports,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function send(AuditLog $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;
        if (!is_string($phase)) {
            $phase = null;
        }

        foreach ($this->transports as $transport) {
            if ($phase !== null && !$transport->supports($phase, $context)) {
                continue;
            }

            $transport->send($log, $context);
        }
    }

    #[Override]
    public function supports(string $phase, array $context = []): bool
    {
        return array_any(
            $this->transports instanceof Traversable ? iterator_to_array($this->transports) : $this->transports,
            static fn (AuditTransportInterface $transport) => $transport->supports($phase, $context)
        );
    }
}
