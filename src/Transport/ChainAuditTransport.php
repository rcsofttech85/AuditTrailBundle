<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

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
    public function send(AuditLogInterface $log, array $context = []): void
    {
        $phase = $context['phase'] ?? null;

        foreach ($this->transports as $transport) {
            // If phase is specified, only send to transports that support it
            if (null !== $phase && !$transport->supports($phase, $context)) {
                continue;
            }

            $transport->send($log, $context);
        }
    }

    public function supports(string $phase, array $context = []): bool
    {
        // Optimistic support: return true if ANY transport supports the phase
        foreach ($this->transports as $transport) {
            if ($transport->supports($phase, $context)) {
                return true;
            }
        }

        return false;
    }
}
