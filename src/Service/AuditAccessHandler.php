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
use Rcsofttech\AuditTrailBundle\Http\AuditRequestAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function array_map;
use function in_array;
use function is_bool;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;

class AuditAccessHandler implements ResetInterface
{
    private const array ALLOWED_READ_CRUD_ACTIONS = ['detail', 'show', 'view', 'read'];

    private const array BLOCKED_READ_INTENT_KEYWORDS = ['edit', 'update', 'new', 'create', 'delete', 'remove', 'revert'];

    /** @var array<string, bool> */
    private array $auditedEntities = [];

    /**
     * @var array<string, array{entity: object, em: EntityManagerInterface, access: AuditAccess, context: array<string, mixed>}>
     */
    private array $pendingAccesses = [];

    /** @var array<string, bool> */
    private array $skipAccessCheck = [];

    /** @var array<string, string> */
    private array $resolvedClassNames = [];

    private ?int $readIntentRequestId = null;

    private ?bool $readIntentRequestAllowed = null;

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
        if (!$this->isExplicitReadIntentRequest()) {
            return;
        }

        // Respect #[AuditCondition] — per-instance, not cacheable
        if (!$om instanceof EntityManagerInterface) {
            return;
        }

        $class = $this->resolveEntityClass($entity, $om);

        if (isset($this->skipAccessCheck[$class])) {
            return;
        }

        $accessAttr = $this->auditService->getAccessAttribute($class);
        if ($accessAttr === null) {
            $this->skipAccessCheck[$class] = true;

            return;
        }

        if (!$this->auditService->passesVoters($entity, AuditLogInterface::ACTION_ACCESS)) {
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

        $this->pendingAccesses[$requestKey] = [
            'entity' => $entity,
            'em' => $om,
            'access' => $accessAttr,
            'context' => $this->captureAuditContextSnapshot(),
        ];
    }

    public function markAsAudited(string $requestKey): void
    {
        $this->auditedEntities[$requestKey] = true;
        unset($this->pendingAccesses[$requestKey]);
    }

    public function flushPendingAccesses(): void
    {
        foreach ($this->pendingAccesses as $requestKey => $pending) {
            $em = $pending['em'];
            if (!$em->isOpen()) {
                unset($this->pendingAccesses[$requestKey]);

                continue;
            }

            $this->dispatchAccessAudit(
                $requestKey,
                $pending['entity'],
                $em,
                $pending['access'],
                $pending['context'],
            );

            unset($this->pendingAccesses[$requestKey]);
        }
    }

    public function hasPendingAccesses(): bool
    {
        return $this->pendingAccesses !== [];
    }

    public function reset(): void
    {
        $this->auditedEntities = [];
        $this->pendingAccesses = [];
        $this->skipAccessCheck = [];
        $this->resolvedClassNames = [];
        $this->readIntentRequestId = null;
        $this->readIntentRequestAllowed = null;
    }

    private function resolveEntityId(object $entity, EntityManagerInterface $om): ?string
    {
        $id = $this->idResolver->resolveFromEntity($entity, $om);

        return $id === AuditLogInterface::PENDING_ID ? null : $id;
    }

    private function resolveEntityClass(object $entity, EntityManagerInterface $om): string
    {
        $runtimeClass = $entity::class;

        if (isset($this->resolvedClassNames[$runtimeClass])) {
            return $this->resolvedClassNames[$runtimeClass];
        }

        try {
            return $this->resolvedClassNames[$runtimeClass] = $om->getClassMetadata($runtimeClass)->getName();
        } catch (Throwable) {
            return $this->resolvedClassNames[$runtimeClass] = $runtimeClass;
        }
    }

    /**
     * @param array<string, mixed> $capturedContext
     */
    private function dispatchAccessAudit(
        string $requestKey,
        object $entity,
        EntityManagerInterface $om,
        AuditAccess $accessAttr,
        array $capturedContext = [],
    ): void {
        try {
            $context = [...$capturedContext, 'level' => $accessAttr->level];
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

            if ($this->dispatcher->dispatch($audit, $om, 'post_load', null, $entity)) {
                $this->markPersistentCooldownForRequest($requestKey, $capturedContext, $accessAttr->cooldown);
            }
        } catch (Throwable $e) {
            $this->logger?->error('Failed to log audit access', [
                'entity' => $entity::class,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function isAuditedRequest(Request $request): bool
    {
        $method = $request->getMethod();

        return in_array($method, ['GET', 'HEAD'], true)
            && in_array($method, $this->auditedMethods, true);
    }

    private function isExplicitReadIntentRequest(): bool
    {
        $request = $this->getCurrentAuditedRequest();
        if ($request === null) {
            return false;
        }

        $requestId = spl_object_id($request);
        if ($this->readIntentRequestId === $requestId && $this->readIntentRequestAllowed !== null) {
            return $this->readIntentRequestAllowed;
        }

        if (!$this->isAuditedRequest($request)) {
            return $this->rememberReadIntentDecision($requestId, false);
        }

        return $this->rememberReadIntentDecision($requestId, $this->resolveExplicitReadIntent($request));
    }

    private function rememberReadIntentDecision(int $requestId, bool $allowed): bool
    {
        $this->readIntentRequestId = $requestId;
        $this->readIntentRequestAllowed = $allowed;

        return $allowed;
    }

    private function resolveExplicitReadIntent(Request $request): bool
    {
        $explicitIntent = $request->attributes->get(AuditRequestAttributes::ACCESS_INTENT);
        if (is_bool($explicitIntent)) {
            return $explicitIntent;
        }

        $crudActionDecision = $this->resolveCrudActionReadIntent($request);
        if ($crudActionDecision !== null) {
            return $crudActionDecision;
        }

        return !$this->containsBlockedReadIntentSignal($request);
    }

    private function resolveCrudActionReadIntent(Request $request): ?bool
    {
        $crudAction = $request->attributes->get('crudAction');
        if (!is_string($crudAction) || $crudAction === '') {
            return null;
        }

        return in_array(strtolower($crudAction), self::ALLOWED_READ_CRUD_ACTIONS, true);
    }

    private function containsBlockedReadIntentSignal(Request $request): bool
    {
        foreach ($this->collectReadIntentSignals($request) as $signal) {
            if ($this->containsBlockedReadIntentKeyword($signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectReadIntentSignals(Request $request): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && $value !== '' ? strtolower($value) : null,
            [
                $request->attributes->get('_route'),
                $request->attributes->get('_controller'),
                $request->attributes->get('_route_params')['action'] ?? null,
            ]
        ), static fn (?string $value): bool => $value !== null));
    }

    private function getCurrentAuditedRequest(): ?Request
    {
        $request = $this->requestStack->getCurrentRequest();
        $mainRequest = $this->requestStack->getMainRequest();

        if ($request === null || $mainRequest === null || $request !== $mainRequest) {
            return null;
        }

        return $request;
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

    /**
     * @param array<string, mixed> $capturedContext
     */
    private function markPersistentCooldownForRequest(string $requestKey, array $capturedContext, int $cooldown): void
    {
        if ($cooldown <= 0 || $this->cache === null) {
            return;
        }

        [$class, $id] = explode(':', $requestKey, 2);
        $capturedUserId = $capturedContext[AuditLogInterface::CONTEXT_USER_ID] ?? 'anonymous';
        $cacheKey = $this->generateCacheKey(is_string($capturedUserId) && $capturedUserId !== '' ? $capturedUserId : 'anonymous', $class, $id);
        $this->markPersistentCooldown($cacheKey, $cooldown);
    }

    private function containsBlockedReadIntentKeyword(string $signal): bool
    {
        foreach (self::BLOCKED_READ_INTENT_KEYWORDS as $keyword) {
            if (str_contains($signal, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureAuditContextSnapshot(): array
    {
        $context = [];

        $userId = $this->userResolver->getUserId();
        if ($userId !== null) {
            $context[AuditLogInterface::CONTEXT_USER_ID] = $userId;
        }

        $username = $this->userResolver->getUsername();
        if ($username !== null) {
            $context[AuditLogInterface::CONTEXT_USERNAME] = $username;
        }

        $ipAddress = $this->userResolver->getIpAddress();
        if ($ipAddress !== null) {
            $context[AuditLogInterface::CONTEXT_IP_ADDRESS] = $ipAddress;
        }

        $userAgent = $this->userResolver->getUserAgent();
        if ($userAgent !== null) {
            $context[AuditLogInterface::CONTEXT_USER_AGENT] = $userAgent;
        }

        return $context;
    }
}
