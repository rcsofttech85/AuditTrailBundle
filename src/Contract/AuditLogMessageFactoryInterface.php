<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

interface AuditLogMessageFactoryInterface
{
    public function createQueueMessage(AuditTransportContext $context): AuditLogMessage;

    public function createPersistMessage(AuditTransportContext $context): PersistAuditLogMessage;
}
