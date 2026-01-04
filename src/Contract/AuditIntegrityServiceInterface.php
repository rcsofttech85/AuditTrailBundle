<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface AuditIntegrityServiceInterface
{
    public function isEnabled(): bool;

    public function generateSignature(AuditLogInterface $log): string;

    public function verifySignature(AuditLogInterface $log): bool;

    public function signPayload(string $payload): string;
}
