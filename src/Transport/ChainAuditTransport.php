<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

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
    public function send(AuditLog $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;

        foreach ($this->transports as $transport) {
            // If phase is specified, only send to transports that support it
            if (null !== $phase && !$transport->supports($phase)) {
                continue;
            }

            $transport->send($log, $context);
        }
    }

    public function supports(string $phase): bool
    {
        // Optimistic support: return true if ANY transport supports the phase
        foreach ($this->transports as $transport) {
            if ($transport->supports($phase)) {
                return true;
            }
        }

        return false;
    }
}
