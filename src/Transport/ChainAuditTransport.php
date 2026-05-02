<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use RuntimeException;
use Throwable;

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
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        $completedTransports = [];

        foreach ($this->transports as $transport) {
            if (!$transport->supports($context)) {
                continue;
            }

            try {
                $result = $transport->send($context);
                $completedTransports[] = $transport::class;

                if ($result->isPartial()) {
                    return AuditDeliveryResult::partiallyDelivered(
                        [...$completedTransports, ...$result->completedTransports],
                        $result->failure ?? new RuntimeException('Audit delivery partially failed.'),
                    );
                }
            } catch (Throwable $exception) {
                if ($completedTransports !== []) {
                    return AuditDeliveryResult::partiallyDelivered($completedTransports, $exception);
                }

                throw $exception;
            }
        }

        return AuditDeliveryResult::delivered();
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
