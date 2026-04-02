<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;

final class ChainAuditTransport implements AuditTransportInterface
{
    /** @var list<AuditTransportInterface> */
    private readonly array $transports;

    /**
     * @param iterable<AuditTransportInterface> $transports
     */
    public function __construct(iterable $transports)
    {
        $this->transports = array_values([...$transports]);
    }

    /**
     * The chain is intentionally fail-fast: a transport failure stops delivery
     * to later transports so the dispatcher can apply its configured fallback
     * strategy consistently.
     */
    #[Override]
    public function send(AuditTransportContext $context): void
    {
        foreach ($this->transports as $transport) {
            if (!$transport->supports($context)) {
                continue;
            }

            $transport->send($context);
        }
    }

    /**
     * The chain is intentionally fail-fast: a transport failure stops delivery
     * to later transports so the dispatcher can apply its configured fallback
     * strategy consistently.
     */
    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        return array_any(
            $this->transports,
            static fn (AuditTransportInterface $transport) => $transport->supports($context)
        );
    }
}
