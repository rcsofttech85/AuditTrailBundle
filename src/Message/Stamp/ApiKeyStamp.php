<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Message\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class ApiKeyStamp implements StampInterface
{
    public function __construct(
        public string $apiKey,
    ) {
    }
}
