<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function in_array;
use function sprintf;

final class AuditAccessHandler implements AuditAccessHandlerInterface, ResetInterface
{
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
        private readonly RequestStack $requestStack,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly AuditAccessIntentResolver $intentResolver,
        private readonly AuditAccessCooldownManager $cooldownManager,
        private readonly AuditAccessContextProvider $contextProvider,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $auditedMethods = ['GET'],
    ) {
    }

    /**
     * @param \Doctrine\Persistence\ObjectManager $om
     */
    #[Override]
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

        $capturedContext = $this->contextProvider->capture();
        $requestKey = sprintf('%s:%s', $class, $id);
        if ($this->cooldownManager->shouldSkip(
            $requestKey,
            $class,
            $id,
            $capturedContext[AuditLogInterface::CONTEXT_USER_ID] ?? null,
            $accessAttr->cooldown,
        )) {
            return;
        }

        $this->pendingAccesses[$requestKey] = [
            'entity' => $entity,
            'em' => $om,
            'access' => $accessAttr,
            'context' => $capturedContext,
        ];
    }

    public function markAsAudited(string $requestKey): void
    {
        $this->cooldownManager->markAsAudited($requestKey);
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

    #[Override]
    public function reset(): void
    {
        $this->pendingAccesses = [];
        $this->skipAccessCheck = [];
        $this->resolvedClassNames = [];
        $this->readIntentRequestId = null;
        $this->readIntentRequestAllowed = null;
        $this->cooldownManager->reset();
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
                $context,
                $om,
            );

            if ($this->dispatcher->dispatch($audit, $om, AuditPhase::PostLoad, null, $entity)) {
                $this->cooldownManager->persistForRequest($requestKey, $capturedContext, $accessAttr->cooldown);
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

        return $this->rememberReadIntentDecision(
            $requestId,
            $this->intentResolver->isExplicitReadIntentRequest($request, $this->auditedMethods),
        );
    }

    private function rememberReadIntentDecision(int $requestId, bool $allowed): bool
    {
        $this->readIntentRequestId = $requestId;
        $this->readIntentRequestAllowed = $allowed;

        return $allowed;
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
}
