<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use RuntimeException;

class ThrowingTransport implements AuditTransportInterface
{
    public function send(AuditTransportContext $context): void
    {
        throw new RuntimeException('Transport failed intentionally.');
    }

    public function supports(AuditTransportContext $context): bool
    {
        return true;
    }
}
