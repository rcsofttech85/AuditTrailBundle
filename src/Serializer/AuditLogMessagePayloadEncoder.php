<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Serializer;

use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Encodes queue audit messages into the canonical JSON body used for transport.
 */
final readonly class AuditLogMessagePayloadEncoder
{
    public function encode(AuditLogMessage $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
