<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;

final class NullAuditTransport implements AuditTransportInterface
{
    #[Override]
    public function send(AuditTransportContext $context): void
    {
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        return true;
    }
}
