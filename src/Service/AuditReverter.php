<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
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
        $entity = $this->findEntity($log->getEntityClass(), $log->getEntityId());

        if (null === $entity) {
            throw new \RuntimeException(sprintf(
                'Entity %s:%s not found.',
                $log->getEntityClass(),
                $log->getEntityId()
            ));
        }

        $changes = $this->determineChanges($log, $entity, $force);

        if ($dryRun) {
            return $changes;
        }

        $this->applyAndPersist($entity, $log, $changes);

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function determineChanges(AuditLog $log, object $entity, bool $force): array
    {
        return match ($log->getAction()) {
            AuditLogInterface::ACTION_CREATE => $this->handleRevertCreate($force),
            AuditLogInterface::ACTION_UPDATE => $this->handleRevertUpdate($log, $entity),
            AuditLogInterface::ACTION_SOFT_DELETE => $this->handleRevertSoftDelete($entity),
            default => throw new \RuntimeException(sprintf(
                'Reverting action "%s" is not supported.',
                $log->getAction()
            )),
        };
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function applyAndPersist(object $entity, AuditLog $log, array $changes): void
    {
        $isDelete = isset($changes['action']) && 'delete' === $changes['action'];

        $this->em->wrapInTransaction(function () use ($entity, $isDelete, $log, $changes) {
            if ($isDelete) {
                $this->em->remove($entity);
            } else {
                $this->validateEntity($entity);
                $this->em->persist($entity);
            }

            $this->em->flush();
            $this->createRevertAuditLog($entity, $log, $changes, $isDelete);
        });
    }

    private function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);
        if (count($errors) > 0) {
            throw new \RuntimeException((string) $errors);
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function createRevertAuditLog(object $entity, AuditLog $log, array $changes, bool $isDelete): void
    {
        $revertLog = $this->auditService->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_REVERT,
            $isDelete ? null : $changes,
            null
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
        if (!class_exists($class)) {
            return null;
        }

        $disabledFilters = $this->disableSoftDeleteFilters();

        try {
            /* @var class-string $class */
            return $this->em->find($class, $id);
        } finally {
            $this->enableFilters($disabledFilters);
        }
    }

    /**
     * @return array<string>
     */
    private function disableSoftDeleteFilters(): array
    {
        $filters = $this->em->getFilters();
        $disabled = [];

        foreach ($filters->getEnabledFilters() as $name => $filter) {
            if (str_contains(get_class($filter), 'SoftDeleteableFilter')) {
                $filters->disable($name);
                $disabled[] = $name;
            }
        }

        return $disabled;
    }

    /**
     * @param array<string> $names
     */
    private function enableFilters(array $names): void
    {
        $filters = $this->em->getFilters();
        foreach ($names as $name) {
            $filters->enable($name);
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function applyChanges(object $entity, array $values): array
    {
        $metadata = $this->em->getClassMetadata(get_class($entity));
        $appliedChanges = [];

        foreach ($values as $field => $value) {
            if ($this->shouldSkipField($metadata, $field, $entity, $value)) {
                continue;
            }

            $metadata->setFieldValue($entity, $field, $value);
            $appliedChanges[$field] = $value;
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

        return $metadata->getFieldValue($entity, $field) === $value;
    }

    private function isSoftDeleted(object $entity): bool
    {
        return method_exists($entity, 'getDeletedAt') && null !== $entity->getDeletedAt();
    }

    private function restoreSoftDeleted(object $entity): void
    {
        if (method_exists($entity, 'setDeletedAt')) {
            $entity->setDeletedAt(null);
        }
    }
}
