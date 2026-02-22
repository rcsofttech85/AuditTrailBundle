<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function in_array;
use function preg_replace;
use function sprintf;
use function str_replace;

class AuditAccessHandler implements ResetInterface
{
    /** @var array<string, bool> */
    private array $auditedEntities = [];

    /** @var array<string, bool> */
    private array $skipAccessCheck = [];

    /**
     * @param array<string> $auditedMethods
     */
    public function __construct(
        private readonly AuditServiceInterface $auditService,
        private readonly AuditDispatcherInterface $dispatcher,
        private readonly UserResolverInterface $userResolver,
        private readonly RequestStack $requestStack,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $auditedMethods = ['GET'],
    ) {
    }

    /**
     * @param \Doctrine\Persistence\ObjectManager $om
     */
    public function handleAccess(object $entity, $om): void
    {
        if ($this->isAuditedRequest() === false) {
            return;
        }

        $class = $entity::class;

        if (isset($this->skipAccessCheck[$class])) {
            return;
        }

        $accessAttr = $this->auditService->getAccessAttribute($class);
        if ($accessAttr === null) {
            $this->skipAccessCheck[$class] = true;

            return;
        }

        // Respect #[AuditCondition] — per-instance, not cacheable
        if (!$this->auditService->passesVoters($entity, AuditLogInterface::ACTION_ACCESS)) {
            return;
        }

        if (!$om instanceof EntityManagerInterface) {
            return;
        }

        $id = $this->resolveEntityId($entity, $om);
        if ($id === null) {
            return;
        }

        $requestKey = sprintf('%s:%s', $class, $id);
        if ($this->shouldSkipAccessLog($requestKey, $class, $id, $accessAttr->cooldown)) {
            return;
        }

        $this->dispatchAccessAudit($entity, $om, $accessAttr);
    }

    public function markAsAudited(string $requestKey): void
    {
        if ($this->isAuditedRequest() === false) {
            return;
        }

        $this->auditedEntities[$requestKey] = true;
    }

    public function reset(): void
    {
        $this->auditedEntities = [];
        $this->skipAccessCheck = [];
    }

    private function resolveEntityId(object $entity, EntityManagerInterface $om): ?string
    {
        $id = $this->idResolver->resolveFromEntity($entity, $om);

        return $id === AuditLogInterface::PENDING_ID ? null : $id;
    }

    private function dispatchAccessAudit(object $entity, EntityManagerInterface $om, AuditAccess $accessAttr): void
    {
        try {
            $context = ['level' => $accessAttr->level];
            if ($accessAttr->message !== null) {
                $context['message'] = $accessAttr->message;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLogInterface::ACTION_ACCESS,
                null,
                null,
                $context
            );

            $this->dispatcher->dispatch($audit, $om, 'post_load');
        } catch (Throwable $e) {
            $this->logger?->error('Failed to log audit access', [
                'entity' => $entity::class,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function isAuditedRequest(): bool
    {
        $method = $this->requestStack->getCurrentRequest()?->getMethod();

        return $method !== null && in_array($method, $this->auditedMethods, true);
    }

    private function shouldSkipAccessLog(string $requestKey, string $class, string $id, int $cooldown): bool
    {
        if (isset($this->auditedEntities[$requestKey])) {
            return true;
        }

        if ($cooldown > 0 && $this->cache !== null) {
            $cacheKey = $this->generateCacheKey($this->userResolver->getUserId() ?? 'anonymous', $class, $id);
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                $this->auditedEntities[$requestKey] = true;

                return true;
            }

            $this->markPersistentCooldown($cacheKey, $cooldown);
        }

        $this->auditedEntities[$requestKey] = true;

        return false;
    }

    private function generateCacheKey(string $userId, string $class, string $id): string
    {
        $rawKey = sprintf('audit_access.%s.%s.%s', $userId, str_replace('\\', '_', $class), $id);

        return (string) preg_replace('/[{}()\\/@:]/', '_', $rawKey);
    }

    private function markPersistentCooldown(string $cacheKey, int $cooldown): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $item = $this->cache->getItem($cacheKey);
            $item->set(true);
            $item->expiresAfter($cooldown);
            $this->cache->save($item);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to save audit cooldown to cache', [
                'key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
