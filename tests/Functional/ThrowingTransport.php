<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditDeliveryResult;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use RuntimeException;

use function in_array;

class ThrowingTransport implements AuditTransportInterface
{
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        throw new RuntimeException('Transport failed intentionally.');
    }

    public function supports(AuditTransportContext $context): bool
    {
        $supportedPhases = TestKernel::$throwingTransportSupportedPhases;
        if ($supportedPhases !== null && !in_array($context->phase->value, $supportedPhases, true)) {
            return false;
        }

        return true;
    }
}
