<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function count;
use function sprintf;

final readonly class AuditReverter implements AuditReverterInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private AuditServiceInterface $auditService,
        private RevertValueDenormalizer $denormalizer,
        private SoftDeleteHandlerInterface $softDeleteHandler,
        private AuditIntegrityServiceInterface $integrityService,
        private AuditDispatcherInterface $dispatcher,
        private ValueSerializerInterface $serializer,
        private ScheduledAuditManagerInterface $auditManager,
        private AuditLogRepositoryInterface $repository,
        private RevertPlanBuilder $revertPlanBuilder,
        private RevertEntityStateApplier $revertStateApplier,
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
        $this->guardRevertability($log, $verifySignature);

        if ($silenceSubscriber) {
            $this->auditManager->disable();
        }

        try {
            $entity = $this->findEntity($log->entityClass, $log->entityId);
            if ($entity === null) {
                throw new RuntimeException(sprintf('Entity %s:%s not found.', $log->entityClass, $log->entityId));
            }

            $revertData = $this->determineChanges($log, $entity, $force, $dryRun);
            $changes = $revertData['changes'];
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

    private function guardRevertability(AuditLog $log, bool $verifySignature): void
    {
        if ($verifySignature && $this->integrityService->isEnabled() && !$this->integrityService->verifySignature($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has been tampered with and cannot be reverted.', $log->id?->toRfc4122() ?? 'unknown'));
        }

        if ($this->repository->isReverted($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has already been reverted.', $log->id?->toRfc4122() ?? 'unknown'));
        }
    }

    /**
     * @return array{
     *     changes: array<string, mixed>,
     *     previousValues?: array<string, mixed>,
     *     fieldValues?: array<string, mixed>,
     *     restoreSoftDelete?: bool
     * }
     */
    private function determineChanges(AuditLog $log, object $entity, bool $force, bool $dryRun): array
    {
        return match ($log->action) {
            AuditLogInterface::ACTION_CREATE => $this->handleRevertCreate($force),
            AuditLogInterface::ACTION_UPDATE => $this->handleRevertUpdate($log, $entity, $dryRun),
            AuditLogInterface::ACTION_SOFT_DELETE => $this->handleRevertSoftDelete($entity, $dryRun),
            AuditLogInterface::ACTION_ACCESS => ['changes' => []],
            default => throw new RuntimeException(sprintf('Reverting action "%s" is not supported.', $log->action)),
        };
    }

    /**
     * @param array{
     *     changes: array<string, mixed>,
     *     previousValues?: array<string, mixed>,
     *     fieldValues?: array<string, mixed>,
     *     restoreSoftDelete?: bool
     * } $revertData
     * @param array<string, mixed> $context
     */
    private function applyAndPersist(
        object $entity,
        AuditLog $log,
        array $revertData,
        array $context,
    ): void {
        $changes = $revertData['changes'];
        $isDelete = isset($changes['action']) && $changes['action'] === 'delete';

        $this->em->wrapInTransaction(function () use ($entity, $isDelete, $log, $changes, $context, $revertData) {
            try {
                if ($isDelete) {
                    $this->em->remove($entity);
                } else {
                    $this->revertStateApplier->apply($entity, $revertData);
                    $this->validateEntity($entity);
                }

                $this->em->flush();
                $this->createRevertAuditLog($entity, $log, $changes, $revertData['previousValues'] ?? [], $isDelete, $context);
            } catch (Throwable $e) {
                if (!$isDelete && $this->em->isOpen()) {
                    $this->em->refresh($entity);
                }

                throw $e;
            }
        });
    }

    private function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);
        if (0 !== count($errors)) {
            throw new RuntimeException((string) $errors);
        }
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $previousValues
     * @param array<string, mixed> $context
     */
    private function createRevertAuditLog(
        object $entity,
        AuditLog $log,
        array $changes,
        array $previousValues,
        bool $isDelete,
        array $context,
    ): void {
        $revertContext = [
            ...$context,
            'reverted_log_id' => $log->id?->toRfc4122(),
        ];

        $serializedChanges = [];
        foreach ($changes as $field => $value) {
            $serializedChanges[$field] = $this->serializer->serialize($value);
        }

        $revertLog = $this->auditService->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_REVERT,
            $previousValues !== [] ? $previousValues : null,
            $isDelete ? null : $serializedChanges,
            $revertContext,
            $this->em,
        );

        if ($revertLog->entityId === AuditLogInterface::PENDING_ID) {
            $revertLog->entityId = $log->entityId;
        }

        if (!$this->dispatcher->dispatch($revertLog, $this->em, AuditPhase::PostFlush, null, $entity)) {
            throw new RuntimeException(sprintf('Failed to dispatch revert audit log for %s:%s.', $log->entityClass, $log->entityId));
        }
    }

    /**
     * @return array{changes: array<string, mixed>}
     */
    private function handleRevertCreate(bool $force): array
    {
        if (!$force) {
            throw new RuntimeException('Reverting a creation (deleting the entity) requires --force.');
        }

        return ['changes' => ['action' => 'delete']];
    }

    /**
     * @return array{changes: array<string, mixed>, previousValues: array<string, mixed>, fieldValues: array<string, mixed>}
     */
    private function handleRevertUpdate(AuditLog $log, object $entity, bool $dryRun): array
    {
        $oldValues = $log->oldValues ?? [];
        if ($oldValues === []) {
            throw new RuntimeException('No old values found in audit log to revert to.');
        }

        return $this->revertPlanBuilder->build($entity, $oldValues, $dryRun);
    }

    /**
     * @return array{changes: array<string, mixed>}
     */
    private function handleRevertSoftDelete(object $entity, bool $dryRun): array
    {
        if ($this->softDeleteHandler->isSoftDeleted($entity)) {
            return [
                'changes' => ['action' => 'restore'],
                'restoreSoftDelete' => !$dryRun,
            ];
        }

        return ['changes' => ['info' => 'Entity is not soft-deleted.']];
    }

    private function findEntity(string $class, string $id): ?object
    {
        if (!class_exists($class)) {
            return null;
        }

        $disabledFilters = $this->softDeleteHandler->disableSoftDeleteFilters();

        try {
            /** @var class-string<object> $class */
            return $this->em->find($class, $this->denormalizer->normalizeEntityIdentifier($class, $id));
        } finally {
            $this->softDeleteHandler->enableFilters($disabledFilters);
        }
    }
}
