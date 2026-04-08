<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

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
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
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
        if ($cooldown <= 0 || $this->cache === null) {
            return;
        }

        [$class, $id] = $this->splitRequestKey($requestKey);
        $cacheKey = $this->generateCacheKey(
            $this->resolveCooldownUserId($capturedContext),
            $class,
            $id,
        );

        try {
            $this->storeCooldownMarker($cacheKey, $cooldown);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to save audit cooldown to cache', [
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

        return (string) preg_replace('/[{}()\\/@:]/', '_', $rawKey);
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
}
