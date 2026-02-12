<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Stringable;
use Throwable;

use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class AuditIntegrityService implements AuditIntegrityServiceInterface
{
    public function __construct(
        private string $secret,
        private string $algorithm = 'sha256',
        private bool $enabled = false,
    ) {
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    #[Override]
    public function generateSignature(AuditLogInterface $log): string
    {
        $data = $this->getLogData($log);

        return hash_hmac($this->algorithm, $data, $this->secret);
    }

    #[Override]
    public function signPayload(string $payload): string
    {
        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    #[Override]
    public function verifySignature(AuditLogInterface $log): bool
    {
        $signature = $log->getSignature();
        if ($signature === null) {
            return AuditLogInterface::ACTION_REVERT === $log->getAction();
        }

        $expectedSignature = $this->generateSignature($log);

        return hash_equals($expectedSignature, $signature);
    }

    private function getLogData(AuditLogInterface $log): string
    {
        $data = [
            $log->getEntityClass(),
            (string) (is_scalar($id = $this->normalize($log->getEntityId())) || $id instanceof Stringable ? $id : ''),
            $log->getAction(),
            $this->toJson($log->getOldValues()),
            $this->toJson($log->getNewValues()),
            (string) (is_scalar($userId = $this->normalize($log->getUserId())) || $userId instanceof Stringable ? $userId : ''),
            $log->getUsername() ?? '',
            $log->getIpAddress() ?? '',
            $log->getUserAgent() ?? '',
            $log->getTransactionHash() ?? '',
            $log->getCreatedAt()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
        ];

        return implode('|', $data);
    }

    /**
     * Normalizes data for hashing to ensure stability across type changes (e.g., int to string IDs).
     */
    private function normalize(mixed $data): mixed
    {
        if (is_array($data)) {
            return $this->normalizeArray($data);
        }

        if (is_int($data) || is_float($data)) {
            return (string) $data;
        }

        if ($data instanceof DateTimeInterface) {
            return $this->normalizeDateTime($data);
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function normalizeArray(array $data): mixed
    {
        // Check if this is a serialized DateTime array
        if (isset($data['date'], $data['timezone']) && is_string($data['date']) && is_string($data['timezone'])) {
            try {
                $dt = new DateTimeImmutable($data['date'], new DateTimeZone($data['timezone']));

                return $this->normalizeDateTime($dt);
            } catch (Throwable) {
                // Fallback to standard array normalization if it's not a valid date
            }
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[$key] = $this->normalize($value);
        }
        ksort($normalized);

        return $normalized;
    }

    private function normalizeDateTime(DateTimeInterface $dt): string
    {
        $immutable = $dt instanceof DateTimeImmutable
            ? $dt
            : DateTimeImmutable::createFromInterface($dt);

        return $immutable->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    private function toJson(mixed $data): string
    {
        if ($data === null) {
            return 'null';
        }

        return json_encode(
            $this->normalize($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
