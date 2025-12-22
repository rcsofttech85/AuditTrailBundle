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
        foreach ($this->transports as $transport) {
            $transport->send($log, $context);
        }
    }
}
