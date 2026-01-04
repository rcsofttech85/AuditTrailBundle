<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

final readonly class AuditIntegrityService implements AuditIntegrityServiceInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'sha256',
        private readonly bool $enabled = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function generateSignature(AuditLogInterface $log): string
    {
        $data = $this->getLogData($log);

        return hash_hmac($this->algorithm, $data, $this->secret);
    }

    public function signPayload(string $payload): string
    {
        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    public function verifySignature(AuditLogInterface $log): bool
    {
        $signature = $log->getSignature();
        if (null === $signature) {
            return AuditLogInterface::ACTION_REVERT === $log->getAction();
        }

        $expectedSignature = $this->generateSignature($log);

        return hash_equals($expectedSignature, $signature);
    }

    private function getLogData(AuditLogInterface $log): string
    {
        $data = [
            $log->getEntityClass(),
            $log->getEntityId(),
            $log->getAction(),
            json_encode($log->getOldValues(), JSON_THROW_ON_ERROR),
            json_encode($log->getNewValues(), JSON_THROW_ON_ERROR),
            $log->getUserId(),
            $log->getUsername(),
            $log->getIpAddress(),
            $log->getUserAgent(),
            $log->getTransactionHash(),
            $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        return implode('|', $data);
    }
}
