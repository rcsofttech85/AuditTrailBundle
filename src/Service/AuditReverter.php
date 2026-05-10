<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditToggleInterface;
use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function count;
use function sprintf;

final readonly class AuditReverter implements AuditReverterInterface
{
    /**
     * @param iterable<RevertActionHandlerInterface> $actionHandlers
     */
    public function __construct(
        private EntityManagerResolver $entityManagerResolver,
        private ValidatorInterface $validator,
        private RevertValueDenormalizer $denormalizer,
        private SoftDeleteFilterManager $softDeleteFilterManager,
        private AuditToggleInterface $auditManager,
        private RevertGuard $revertGuard,
        private RevertEntityStateApplier $revertStateApplier,
        private RevertAuditLogCreator $revertAuditLogCreator,
        private AuditDispatcherInterface $auditDispatcher,
        #[AutowireIterator('audit_trail.revert_action_handler')]
        private iterable $actionHandlers,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function revert(
        AuditLog $log,
        bool $dryRun = false,
        bool $force = false,
        array $context = [],
        bool $silenceSubscriber = true,
        bool $verifySignature = true,
    ): array {
        $this->revertGuard->assertRevertable($log, $verifySignature);

        if ($silenceSubscriber) {
            $this->auditManager->disable();
        }

        try {
            $entity = $this->findEntity($log->entityClass, $log->requireEntityId());
            if ($entity === null) {
                throw new RuntimeException(sprintf('Entity %s:%s not found.', $log->entityClass, $log->requireEntityId()));
            }

            $revertData = $this->determineChanges($log, $entity, $force, $dryRun);
            $changes = $revertData->changes;
            if ($dryRun) {
                return $changes;
            }

            $this->applyAndPersist($entity, $log, $revertData, $context);

            return $changes;
        } finally {
            if ($silenceSubscriber) {
                $this->auditManager->enable();
            }
        }
    }

    private function determineChanges(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan
    {
        foreach ($this->actionHandlers as $handler) {
            if ($handler->supports($log->action)) {
                return $handler->buildPlan($log, $entity, $force, $dryRun);
            }
        }

        throw new RuntimeException(sprintf('Reverting action "%s" is not supported.', $log->action->value));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function applyAndPersist(
        object $entity,
        AuditLog $log,
        RevertPlan $revertPlan,
        array $context,
    ): void {
        $entityManager = $this->entityManagerResolver->requireForObject($entity);
        $changes = $revertPlan->changes;
        $isDelete = $revertPlan->isDeleteAction();
        $dispatchedInTransaction = false;

        $revertAudit = $entityManager->wrapInTransaction(function () use ($entityManager, $entity, $isDelete, $log, $changes, $context, $revertPlan, &$dispatchedInTransaction): AuditLog {
            try {
                if ($isDelete) {
                    $entityManager->remove($entity);
                } else {
                    $this->revertStateApplier->apply($entity, $revertPlan);
                    $this->validateEntity($entity);
                }

                $revertAudit = $this->revertAuditLogCreator->create(
                    $entity,
                    $log,
                    $changes,
                    $revertPlan->previousValues,
                    $isDelete,
                    $context,
                    $entityManager,
                );

                $dispatchedInTransaction = $this->auditDispatcher->dispatch(
                    $revertAudit,
                    $entityManager,
                    AuditPhase::OnFlush,
                    null,
                    $entity,
                );

                return $revertAudit;
            } catch (Throwable $e) {
                if (!$isDelete && $entityManager->isOpen()) {
                    $entityManager->refresh($entity);
                }

                throw $e;
            }
        });

        if ($dispatchedInTransaction) {
            return;
        }

        try {
            if ($this->auditDispatcher->dispatch($revertAudit, $entityManager, AuditPhase::PostFlush, null, $entity)) {
                return;
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf('Revert committed for %s:%s, but audit dispatch failed after commit.', $log->entityClass, $log->requireEntityId()), previous: $exception);
        }

        throw new RuntimeException(sprintf('Revert committed for %s:%s, but no audit transport accepted the revert log after commit.', $log->entityClass, $log->requireEntityId()));
    }

    private function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);
        if (0 !== count($errors)) {
            throw new RuntimeException((string) $errors);
        }
    }

    private function findEntity(string $class, string $id): ?object
    {
        if (!class_exists($class)) {
            return null;
        }

        $entityManager = $this->entityManagerResolver->resolveForClass($class);
        if ($entityManager === null) {
            return null;
        }

        $disabledFilters = $this->softDeleteFilterManager->disable($entityManager);

        try {
            /** @var class-string<object> $class */
            return $entityManager->find($class, $this->denormalizer->normalizeEntityIdentifier($class, $id));
        } finally {
            $this->softDeleteFilterManager->enable($entityManager, $disabledFilters);
        }
    }
}
