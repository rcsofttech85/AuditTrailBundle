<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
use RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
        if ($verifySignature && $this->integrityService->isEnabled() && !$this->integrityService->verifySignature($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has been tampered with and cannot be reverted.', $log->id?->toRfc4122() ?? 'unknown'));
        }

        if ($this->repository->isReverted($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has already been reverted.', $log->id?->toRfc4122() ?? 'unknown'));
        }

        if ($silenceSubscriber) {
            $this->auditManager->disable();
        }

        try {
            $entity = $this->findEntity($log->entityClass, $log->entityId);

            if ($entity === null) {
                throw new RuntimeException(sprintf('Entity %s:%s not found.', $log->entityClass, $log->entityId));
            }

            $changes = $this->determineChanges($log, $entity, $force, $dryRun);

            if ($dryRun) {
                return $changes;
            }

            $this->applyAndPersist($entity, $log, $changes, $context);

            return $changes;
        } finally {
            if ($silenceSubscriber) {
                $this->auditManager->enable();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function determineChanges(AuditLog $log, object $entity, bool $force, bool $dryRun): array
    {
        return match ($log->action) {
            AuditLogInterface::ACTION_CREATE => $this->handleRevertCreate($force),
            AuditLogInterface::ACTION_UPDATE => $this->handleRevertUpdate($log, $entity, $dryRun),
            AuditLogInterface::ACTION_SOFT_DELETE => $this->handleRevertSoftDelete($entity),
            AuditLogInterface::ACTION_ACCESS => [], // Ignore access attribute during revert
            default => throw new RuntimeException(sprintf('Reverting action "%s" is not supported.', $log->action)),
        };
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $context
     */
    private function applyAndPersist(
        object $entity,
        AuditLog $log,
        array $changes,
        array $context,
    ): void {
        $isDelete = isset($changes['action']) && $changes['action'] === 'delete';

        $this->em->wrapInTransaction(function () use ($entity, $isDelete, $log, $changes, $context) {
            if ($isDelete) {
                $this->em->remove($entity);
            } else {
                $this->validateEntity($entity);
                $this->em->persist($entity);
            }

            $this->em->flush();

            $this->createRevertAuditLog($entity, $log, $changes, $isDelete, $context);
        });
    }

    private function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);
        if (count($errors) > 0) {
            throw new RuntimeException((string) $errors);
        }
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $context
     */
    private function createRevertAuditLog(
        object $entity,
        AuditLog $log,
        array $changes,
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
            $isDelete ? null : $serializedChanges,
            null,
            $revertContext
        );

        if ($revertLog->entityId === AuditLogInterface::PENDING_ID) {
            $revertLog->entityId = $log->entityId;
        }

        $this->dispatcher->dispatch($revertLog, $this->em, 'post_flush');
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRevertCreate(bool $force): array
    {
        if (!$force) {
            throw new RuntimeException('Reverting a creation (deleting the entity) requires --force.');
        }

        return ['action' => 'delete'];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRevertUpdate(AuditLog $log, object $entity, bool $dryRun): array
    {
        $oldValues = $log->oldValues ?? [];
        if ($oldValues === []) {
            throw new RuntimeException('No old values found in audit log to revert to.');
        }

        return $this->applyChanges($entity, $oldValues, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRevertSoftDelete(object $entity): array
    {
        if ($this->softDeleteHandler->isSoftDeleted($entity)) {
            $this->softDeleteHandler->restoreSoftDeleted($entity);

            return ['action' => 'restore'];
        }

        return ['info' => 'Entity is not soft-deleted.'];
    }

    private function findEntity(string $class, string $id): ?object
    {
        if (!class_exists($class)) {
            return null;
        }

        $disabledFilters = $this->softDeleteHandler->disableSoftDeleteFilters();

        try {
            /* @var class-string $class */
            return $this->em->find($class, $id);
        } finally {
            $this->softDeleteHandler->enableFilters($disabledFilters);
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function applyChanges(object $entity, array $values, bool $dryRun): array
    {
        $metadata = $this->em->getClassMetadata($entity::class);
        $appliedChanges = [];

        foreach ($values as $field => $value) {
            $denormalizedValue = $this->denormalizer->denormalize($metadata, $field, $value, $dryRun);

            if ($this->shouldSkipField($metadata, $field, $entity, $denormalizedValue)) {
                continue;
            }

            $metadata->setFieldValue($entity, $field, $denormalizedValue);
            $appliedChanges[$field] = $denormalizedValue;
        }

        return $appliedChanges;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function shouldSkipField(ClassMetadata $metadata, string $field, object $entity, mixed $value): bool
    {
        if ($metadata->isIdentifier($field) || (!$metadata->hasField($field) && !$metadata->hasAssociation($field))) {
            return true;
        }

        $currentValue = $metadata->getFieldValue($entity, $field);

        return $this->denormalizer->valuesAreEqual($currentValue, $value);
    }
}
