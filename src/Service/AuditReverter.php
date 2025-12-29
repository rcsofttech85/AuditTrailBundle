<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AuditReverter implements AuditReverterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function revert(AuditLog $log, bool $dryRun = false, bool $force = false): array
    {
        $entityClass = $log->getEntityClass();
        $entityId = $log->getEntityId();
        $action = $log->getAction();

        // 1. Fetch Entity (handling soft-deletes)
        $entity = $this->findEntity($entityClass, $entityId);

        if (null === $entity) {
            throw new \RuntimeException(sprintf('Entity %s:%s not found.', $entityClass, $entityId));
        }

        // 2. Determine changes
        $changes = match ($action) {
            AuditLog::ACTION_CREATE => $this->handleRevertCreate($force),
            AuditLog::ACTION_UPDATE => $this->handleRevertUpdate($log, $entity),
            AuditLog::ACTION_SOFT_DELETE => $this->handleRevertSoftDelete($entity),
            default => throw new \RuntimeException(sprintf('Reverting action "%s" is not supported.', $action)),
        };

        if ($dryRun) {
            return $changes;
        }

        $isDelete = isset($changes['action']) && 'delete' === $changes['action'];

        // 3. Apply and Persist
        $this->em->wrapInTransaction(function () use ($entity, $isDelete, $log, $changes) {
            if ($isDelete) {
                $this->em->remove($entity);
            } else {
                // Validate if updating/restoring
                $errors = $this->validator->validate($entity);
                if (count($errors) > 0) {
                    throw new \RuntimeException((string) $errors);
                }
                $this->em->persist($entity);
            }

            $this->em->flush();

            // Create Revert Audit Log
            $revertLog = $this->auditService->createAuditLog(
                $entity,
                AuditLog::ACTION_REVERT,
                $isDelete ? null : $changes,
                null
            );

            $revertLog->setOldValues($isDelete ? null : $changes);
            $revertLog->setNewValues(null);
            $revertLog->setEntityId($log->getEntityId());
            $revertLog->setEntityClass($log->getEntityClass());

            $this->em->persist($revertLog);
            $this->em->flush();
        });

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRevertCreate(bool $force): array
    {
        if (!$force) {
            throw new \RuntimeException('Reverting a creation (deleting the entity) requires --force.');
        }

        return ['action' => 'delete'];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRevertUpdate(AuditLog $log, object $entity): array
    {
        $oldValues = $log->getOldValues() ?? [];
        if ([] === $oldValues) {
            throw new \RuntimeException('No old values found in audit log to revert to.');
        }

        return $this->applyChanges($entity, $oldValues);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRevertSoftDelete(object $entity): array
    {
        if ($this->isSoftDeleted($entity)) {
            $this->restoreSoftDeleted($entity);

            return ['action' => 'restore'];
        }

        return ['info' => 'Entity is not soft-deleted.'];
    }

    private function findEntity(string $class, string $id): ?object
    {
        $filters = $this->em->getFilters();
        $softDeleteFilterName = null;

        // Dynamically find the soft-delete filter
        foreach ($filters->getEnabledFilters() as $name => $filter) {
            if (is_a($filter, 'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter')) {
                $softDeleteFilterName = $name;
                break;
            }
        }

        if (null !== $softDeleteFilterName) {
            $filters->disable($softDeleteFilterName);
        }

        if (!class_exists($class)) {
            return null;
        }

        try {
            /* @var class-string<object> $class */
            return $this->em->find($class, $id);
        } finally {
            if (null !== $softDeleteFilterName) {
                $filters->enable($softDeleteFilterName);
            }
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
            if ($metadata->isIdentifier($field)) {
                continue; // Never revert identifiers
            }

            if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
                continue; // Ignore unmapped fields
            }

            $currentValue = $metadata->getFieldValue($entity, $field);

            if ($currentValue !== $value) {
                $metadata->setFieldValue($entity, $field, $value);
                $appliedChanges[$field] = $value;
            }
        }

        return $appliedChanges;
    }

    private function isSoftDeleted(object $entity): bool
    {
        if (method_exists($entity, 'getDeletedAt')) {
            return null !== $entity->getDeletedAt();
        }

        return false;
    }

    private function restoreSoftDeleted(object $entity): void
    {
        if (method_exists($entity, 'setDeletedAt')) {
            $entity->setDeletedAt(null);
        }
    }
}
