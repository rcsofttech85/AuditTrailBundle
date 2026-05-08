<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function hash;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strstr;
use function substr;

final class AuditAccessCooldownManager implements ResetInterface
{
    private const string CACHE_KEY_PREFIX = 'audit_access.';

    private const int MAX_CACHE_KEY_LENGTH = 64;

    /** @var array<string, bool> */
    private array $auditedEntities = [];

    public function __construct(
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function markAsAudited(string $requestKey): void
    {
        $this->auditedEntities[$requestKey] = true;
    }

    public function shouldSkip(string $requestKey, string $class, string $id, ?string $userId, int $cooldown): bool
    {
        if (isset($this->auditedEntities[$requestKey])) {
            return true;
        }

        if ($cooldown > 0 && $this->cache !== null) {
            $cacheKey = $this->generateCacheKey($userId ?? 'anonymous', $class, $id);
            try {
                $item = $this->cache->getItem($cacheKey);
            } catch (Throwable $e) {
                $this->logger?->warning('Failed to read audit cooldown from cache', [
                    'key' => $cacheKey,
                    'exception' => $e->getMessage(),
                ]);
                $item = null;
            }

            if ($item?->isHit() === true) {
                $this->auditedEntities[$requestKey] = true;

                return true;
            }
        }

        $this->auditedEntities[$requestKey] = true;

        return false;
    }

    /**
     * @param array<string, mixed> $capturedContext
     */
    public function persistForRequest(string $requestKey, array $capturedContext, int $cooldown): void
    {
        if ($cooldown <= 0) {
            return;
        }

        $cacheKey = $this->resolveCacheKey($requestKey, $capturedContext);
        if ($cacheKey === null) {
            return;
        }

        try {
            $this->storeCooldownMarker($cacheKey, $cooldown);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to save audit cooldown to cache', [
                'key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $capturedContext
     */
    public function clearForRequest(string $requestKey, array $capturedContext): void
    {
        $cacheKey = $this->resolveCacheKey($requestKey, $capturedContext);
        if ($cacheKey === null) {
            return;
        }

        try {
            $this->deleteCooldownMarker($cacheKey);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to clear audit cooldown from cache', [
                'key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->auditedEntities = [];
    }

    private function generateCacheKey(string $userId, string $class, string $id): string
    {
        $rawKey = sprintf('audit_access.%s.%s.%s', $userId, str_replace('\\', '_', $class), $id);
        $sanitized = (string) preg_replace('/[{}()\\/@:]/', '_', $rawKey);

        if (strlen($sanitized) <= self::MAX_CACHE_KEY_LENGTH) {
            return $sanitized;
        }

        return self::CACHE_KEY_PREFIX.substr(hash('sha256', $sanitized), 0, 48);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRequestKey(string $requestKey): array
    {
        if (!str_contains($requestKey, ':')) {
            return [$requestKey, ''];
        }

        /** @var string $class */
        $class = strstr($requestKey, ':', true);
        /** @var string $id */
        $id = substr($requestKey, strlen($class) + 1);

        return [$class, $id];
    }

    /**
     * @param array<string, mixed> $capturedContext
     */
    private function resolveCooldownUserId(array $capturedContext): string
    {
        $capturedUserId = $capturedContext[AuditLogInterface::CONTEXT_USER_ID] ?? null;

        return is_string($capturedUserId) && $capturedUserId !== '' ? $capturedUserId : 'anonymous';
    }

    /**
     * @param array<string, mixed> $capturedContext
     */
    private function resolveCacheKey(string $requestKey, array $capturedContext): ?string
    {
        if ($this->cache === null) {
            return null;
        }

        [$class, $id] = $this->splitRequestKey($requestKey);

        return $this->generateCacheKey(
            $this->resolveCooldownUserId($capturedContext),
            $class,
            $id,
        );
    }

    private function storeCooldownMarker(string $cacheKey, int $cooldown): void
    {
        $cache = $this->cache;
        if ($cache === null) {
            return;
        }

        $item = $cache->getItem($cacheKey);
        $item->set(true);
        $item->expiresAfter($cooldown);
        $cache->save($item);
    }

    private function deleteCooldownMarker(string $cacheKey): void
    {
        $cache = $this->cache;
        if ($cache === null) {
            return;
        }

        $cache->deleteItem($cacheKey);
    }
}
