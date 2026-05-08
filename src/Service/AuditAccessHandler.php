<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAccessAudit;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;

final class AuditAccessHandler implements AuditAccessHandlerInterface, ResetInterface
{
    /**
     * @var array<string, PendingAccessAudit>
     */
    private array $pendingAccesses = [];

    /** @var array<string, bool> */
    private array $skipAccessCheck = [];

    private readonly EntityClassResolver $entityClassResolver;

    public function __construct(
        private readonly AuditServiceInterface $auditService,
        private readonly AuditAccessLogDispatcher $accessLogDispatcher,
        private readonly AuditAccessRequestEvaluator $requestEvaluator,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly AuditAccessCooldownManager $cooldownManager,
        private readonly AuditAccessContextProvider $contextProvider,
        ?EntityClassResolver $entityClassResolver = null,
    ) {
        $this->entityClassResolver = $entityClassResolver ?? new EntityClassResolver();
    }

    /**
     * @param \Doctrine\Persistence\ObjectManager $om
     */
    #[Override]
    public function handleAccess(object $entity, $om): void
    {
        if (!$this->requestEvaluator->allowsAccessAudit()) {
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

        if (!$this->auditService->passesVoters($entity, AuditAction::Access)) {
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

        $this->cooldownManager->persistForRequest($requestKey, $capturedContext, $accessAttr->cooldown);
        $this->pendingAccesses[$requestKey] = new PendingAccessAudit($requestKey, $entity, $om, $accessAttr, $capturedContext);
    }

    public function markAsAudited(string $requestKey): void
    {
        $this->cooldownManager->markAsAudited($requestKey);
        $this->discardPendingAccess($requestKey);
    }

    public function flushPendingAccesses(): void
    {
        foreach ($this->pendingAccesses as $requestKey => $pending) {
            $em = $pending->entityManager;
            if (!$em->isOpen()) {
                $this->discardPendingAccess($requestKey);

                continue;
            }

            $this->accessLogDispatcher->dispatch($pending);
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
        foreach (array_keys($this->pendingAccesses) as $requestKey) {
            $this->discardPendingAccess($requestKey);
        }

        $this->skipAccessCheck = [];
        $this->requestEvaluator->reset();
        $this->cooldownManager->reset();
    }

    private function discardPendingAccess(string $requestKey): void
    {
        $pending = $this->pendingAccesses[$requestKey] ?? null;
        if ($pending === null) {
            return;
        }

        $this->cooldownManager->clearForRequest($pending->requestKey, $pending->context);
        unset($this->pendingAccesses[$requestKey]);
    }

    private function resolveEntityId(object $entity, EntityManagerInterface $om): ?string
    {
        return $this->idResolver->resolveFromEntity($entity, $om);
    }

    private function resolveEntityClass(object $entity, EntityManagerInterface $om): string
    {
        return $this->entityClassResolver->resolve($entity, $om);
    }
}
