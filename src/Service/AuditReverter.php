<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;
use function sprintf;

class AuditReverter implements AuditReverterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly AuditService $auditService,
        private readonly RevertValueDenormalizer $denormalizer,
        private readonly SoftDeleteHandler $softDeleteHandler,
        private readonly AuditIntegrityServiceInterface $integrityService,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function revert(
        AuditLogInterface $log,
        bool $dryRun = false,
        bool $force = false,
        array $context = [],
    ): array {
        if ($this->integrityService->isEnabled() && !$this->integrityService->verifySignature($log)) {
            throw new RuntimeException(sprintf('Audit log #%s has been tampered with and cannot be reverted.', $log->getId() ?? 'unknown'));
        }

        $entity = $this->findEntity($log->getEntityClass(), $log->getEntityId());

        if ($entity === null) {
            throw new RuntimeException(sprintf('Entity %s:%s not found.', $log->getEntityClass(), $log->getEntityId()));
        }

        $changes = $this->determineChanges($log, $entity, $force);

        if ($dryRun) {
            return $changes;
        }

        $this->applyAndPersist($entity, $log, $changes, $context);

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function determineChanges(AuditLogInterface $log, object $entity, bool $force): array
    {
        return match ($log->getAction()) {
            AuditLogInterface::ACTION_CREATE => $this->handleRevertCreate($force),
            AuditLogInterface::ACTION_UPDATE => $this->handleRevertUpdate($log, $entity),
            AuditLogInterface::ACTION_SOFT_DELETE => $this->handleRevertSoftDelete($entity),
            default => throw new RuntimeException(sprintf('Reverting action "%s" is not supported.', $log->getAction())),
        };
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $context
     */
    private function applyAndPersist(object $entity, AuditLogInterface $log, array $changes, array $context): void
    {
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
        AuditLogInterface $log,
        array $changes,
        bool $isDelete,
        array $context,
    ): void {
        $revertContext = [
            ...$context,
            'reverted_log_id' => $log->getId(),
        ];

        $revertLog = $this->auditService->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_REVERT,
            $isDelete ? null : $changes,
            null,
            $revertContext
        );

        $revertLog->setOldValues($isDelete ? null : $changes);
        $revertLog->setNewValues(null);
        $revertLog->setEntityId($log->getEntityId());
        $revertLog->setEntityClass($log->getEntityClass());

        $this->em->persist($revertLog);
        $this->em->flush();
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
    private function handleRevertUpdate(AuditLogInterface $log, object $entity): array
    {
        $oldValues = $log->getOldValues() ?? [];
        if ($oldValues === []) {
            throw new RuntimeException('No old values found in audit log to revert to.');
        }

        return $this->applyChanges($entity, $oldValues);
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
    private function applyChanges(object $entity, array $values): array
    {
        $metadata = $this->em->getClassMetadata($entity::class);
        $appliedChanges = [];

        foreach ($values as $field => $value) {
            $denormalizedValue = $this->denormalizer->denormalize($metadata, $field, $value);

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
