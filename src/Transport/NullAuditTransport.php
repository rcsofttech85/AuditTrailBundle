<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;

final readonly class NullAuditTransport implements AuditTransportInterface
{
    #[Override]
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        return AuditDeliveryResult::delivered();
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        return true;
    }
}
