<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

interface AuditTransportInterface
{
    public function send(AuditTransportContext $context): void;

    public function supports(AuditTransportContext $context): bool;
}
