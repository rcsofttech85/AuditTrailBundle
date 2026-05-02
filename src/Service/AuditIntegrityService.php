<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use RuntimeException;

use function hash_equals;
use function hash_hmac;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class AuditIntegrityService implements AuditIntegrityServiceInterface
{
    /**
     * @var bool Read-only property check for integrity status using PHP 8.4 hooks.
     */
    public bool $isEnabled {
        get => $this->enabled && $this->secret !== null;
    }

    public function __construct(
        private readonly AuditIntegrityNormalizer $normalizer,
        private(set) ?string $secret = null,
        public private(set) bool $enabled = false,
        public private(set) string $algorithm = 'sha256',
    ) {
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    #[Override]
    public function generateSignature(AuditLog $log): string
    {
        if ($this->secret === null) {
            throw new RuntimeException('Cannot generate signature: secret key is not configured.');
        }

        $payload = json_encode(
            $this->normalizer->normalize($log),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    #[Override]
    public function verifySignature(AuditLog $log): bool
    {
        if (!$this->isEnabled) {
            return true;
        }

        $storedSignature = $log->signature;

        if ($storedSignature === null) {
            return false;
        }

        if ($this->signatureMatches($log, $storedSignature, true)) {
            return true;
        }

        return $this->signatureMatches($log, $storedSignature, false);
    }

    #[Override]
    public function signPayload(string $payload): string
    {
        if ($this->secret === null) {
            throw new RuntimeException('Cannot sign payload: secret key is not configured.');
        }

        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    private function signatureMatches(AuditLog $log, string $storedSignature, bool $includeChangedFields): bool
    {
        if ($this->secret === null) {
            throw new RuntimeException('Cannot verify signature: secret key is not configured.');
        }

        $payload = json_encode(
            $this->normalizer->normalize($log, $includeChangedFields),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $expectedSignature = hash_hmac($this->algorithm, $payload, $this->secret);

        return hash_equals($expectedSignature, $storedSignature);
    }
}
