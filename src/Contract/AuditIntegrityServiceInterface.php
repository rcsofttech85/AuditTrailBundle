<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditIntegrityServiceInterface
{
    public function isEnabled(): bool;

    public function generateSignature(AuditLog $log): string;

    public function verifySignature(AuditLog $log): bool;

    public function signPayload(string $payload): string;
}
