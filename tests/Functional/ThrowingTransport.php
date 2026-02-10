<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use RuntimeException;

class ThrowingTransport implements AuditTransportInterface
{
    public function send(AuditLogInterface $log, array $context = []): void
    {
        throw new RuntimeException('Transport failed intentionally.');
    }

    public function supports(string $phase, array $context = []): bool
    {
        return true;
    }
}
