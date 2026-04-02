<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\Common\Collections\Collection;
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
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function count;
use function is_string;
use function method_exists;
use function preg_replace;
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
            AuditLogInterface::ACTION_ACCESS => ['changes' => []], // Ignore access attribute during revert
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
                    $this->applyRevertData($entity, $revertData);
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

        $this->dispatcher->dispatch($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);
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
     * @return array{changes: array<string, mixed>, previousValues: array<string, mixed>}
     */
    private function handleRevertUpdate(AuditLog $log, object $entity, bool $dryRun): array
    {
        $oldValues = $log->oldValues ?? [];
        if ($oldValues === []) {
            throw new RuntimeException('No old values found in audit log to revert to.');
        }

        return $this->computeChanges($entity, $oldValues, $dryRun);
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
            /* @var class-string $class */
            return $this->em->find($class, $this->denormalizer->normalizeEntityIdentifier($class, $id));
        } finally {
            $this->softDeleteHandler->enableFilters($disabledFilters);
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{changes: array<string, mixed>, previousValues: array<string, mixed>, fieldValues: array<string, mixed>}
     */
    private function computeChanges(object $entity, array $values, bool $dryRun): array
    {
        $metadata = $this->em->getClassMetadata($entity::class);
        $appliedChanges = [];
        $previousValues = [];

        foreach ($values as $field => $value) {
            $denormalizedValue = $this->denormalizer->denormalize($metadata, $field, $value, $dryRun);
            $currentValue = $metadata->getFieldValue($entity, $field);

            if ($this->shouldSkipField($metadata, $field, $denormalizedValue, $currentValue)) {
                continue;
            }

            $appliedChanges[$field] = $denormalizedValue;
            $previousValues[$field] = $this->serializer->serialize($currentValue);
        }

        return [
            'changes' => $appliedChanges,
            'previousValues' => $previousValues,
            'fieldValues' => $appliedChanges,
        ];
    }

    /**
     * @param array{
     *     changes: array<string, mixed>,
     *     previousValues?: array<string, mixed>,
     *     fieldValues?: array<string, mixed>,
     *     restoreSoftDelete?: bool
     * } $revertData
     */
    private function applyRevertData(object $entity, array $revertData): void
    {
        $metadata = $this->em->getClassMetadata($entity::class);

        foreach ($revertData['fieldValues'] ?? [] as $field => $value) {
            $this->applyRevertFieldValue($metadata, $entity, $field, $value);
        }

        if (($revertData['restoreSoftDelete'] ?? false) === true) {
            $this->softDeleteHandler->restoreSoftDeleted($entity);
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function applyRevertFieldValue(ClassMetadata $metadata, object $entity, string $field, mixed $value): void
    {
        if ($metadata->hasAssociation($field) && $metadata->isCollectionValuedAssociation($field)) {
            $this->applyCollectionAssociationRevertData($metadata, $entity, $field, $value);

            return;
        }

        $metadata->setFieldValue($entity, $field, $value);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function shouldSkipField(
        ClassMetadata $metadata,
        string $field,
        mixed $value,
        mixed $currentValue,
    ): bool {
        if ($metadata->isIdentifier($field) || (!$metadata->hasField($field) && !$metadata->hasAssociation($field))) {
            return true;
        }

        if ($metadata->hasAssociation($field) && $metadata->isCollectionValuedAssociation($field)) {
            return $this->collectionValuesAreEqual($currentValue, $value);
        }

        return $this->denormalizer->valuesAreEqual($currentValue, $value);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function applyCollectionAssociationRevertData(
        ClassMetadata $metadata,
        object $entity,
        string $field,
        mixed $value,
    ): void {
        $currentValue = $metadata->getFieldValue($entity, $field);
        if (!$currentValue instanceof Collection || !$value instanceof Collection) {
            $metadata->setFieldValue($entity, $field, $value);

            return;
        }

        $mapping = $metadata->getAssociationMapping($field);

        foreach ($currentValue->toArray() as $currentItem) {
            if (!$value->contains($currentItem)) {
                $this->removeAssociationItem($metadata, $mapping, $entity, $field, $currentValue, $currentItem);
            }
        }

        foreach ($value as $item) {
            if (!$currentValue->contains($item)) {
                $this->addAssociationItem($metadata, $mapping, $entity, $field, $currentValue, $item);
            }
        }
    }

    private function collectionValuesAreEqual(mixed $currentValue, mixed $newValue): bool
    {
        if (!$currentValue instanceof Collection || !$newValue instanceof Collection) {
            return false;
        }

        return $this->normalizeCollectionIdentifiers($currentValue) === $this->normalizeCollectionIdentifiers($newValue);
    }

    /**
     * @param Collection<int|string, object> $collection
     *
     * @return list<string>
     */
    private function normalizeCollectionIdentifiers(Collection $collection): array
    {
        $identifiers = [];

        foreach ($collection as $item) {
            $identifiers[] = $this->normalizeEntityIdentifier($item);
        }

        sort($identifiers);

        return $identifiers;
    }

    private function normalizeEntityIdentifier(object $entity): string
    {
        $metadata = $this->em->getClassMetadata($entity::class);
        $identifierValues = $metadata->getIdentifierValues($entity);

        if ($identifierValues === []) {
            return spl_object_hash($entity);
        }

        $normalized = [];
        foreach ($identifierValues as $field => $value) {
            $normalized[] = sprintf('%s=%s', $field, (string) $value);
        }

        sort($normalized);

        return implode('|', $normalized);
    }

    /**
     * @param ClassMetadata<object>          $metadata
     * @param Collection<int|string, object> $currentValue
     */
    private function addAssociationItem(
        ClassMetadata $metadata,
        mixed $mapping,
        object $entity,
        string $field,
        Collection $currentValue,
        object $item,
    ): void {
        if ($this->invokeCollectionMutator($entity, 'add', $item)) {
            return;
        }

        $currentValue->add($item);
        $this->synchronizeCounterpartAssociation($metadata, $mapping, $entity, $field, $item, true);
    }

    /**
     * @param ClassMetadata<object>          $metadata
     * @param Collection<int|string, object> $currentValue
     */
    private function removeAssociationItem(
        ClassMetadata $metadata,
        mixed $mapping,
        object $entity,
        string $field,
        Collection $currentValue,
        object $item,
    ): void {
        if ($this->invokeCollectionMutator($entity, 'remove', $item)) {
            return;
        }

        $currentValue->removeElement($item);
        $this->synchronizeCounterpartAssociation($metadata, $mapping, $entity, $field, $item, false);
    }

    private function invokeCollectionMutator(object $entity, string $prefix, object $item): bool
    {
        $shortName = new ReflectionClass($item)->getShortName();
        $shortName = $this->normalizeShortClassName($shortName);
        $method = $prefix.$shortName;

        if (!method_exists($entity, $method)) {
            return false;
        }

        $reflectionMethod = new ReflectionMethod($entity, $method);
        if (!$reflectionMethod->isPublic()) {
            return false;
        }

        $reflectionMethod->invoke($entity, $item);

        return true;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function synchronizeCounterpartAssociation(
        ClassMetadata $metadata,
        mixed $mapping,
        object $entity,
        string $field,
        object $item,
        bool $adding,
    ): void {
        $counterpartField = $this->resolveCounterpartField($mapping);
        if (!is_string($counterpartField)) {
            return;
        }

        if ($this->invokeCounterpartMutator($entity, $item, $adding)) {
            return;
        }

        $targetMetadata = $this->em->getClassMetadata($item::class);
        if (!$this->targetMetadataContainsField($targetMetadata, $counterpartField)) {
            return;
        }

        if ($targetMetadata->hasAssociation($counterpartField) && $targetMetadata->isCollectionValuedAssociation($counterpartField)) {
            $this->synchronizeCollectionCounterpart($targetMetadata, $item, $counterpartField, $entity, $adding);

            return;
        }

        $this->synchronizeSingleValuedCounterpart($targetMetadata, $item, $counterpartField, $entity, $adding);
    }

    private function resolveCounterpartField(mixed $mapping): mixed
    {
        return $mapping['mappedBy'] ?? $mapping['inversedBy'] ?? null;
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function targetMetadataContainsField(ClassMetadata $targetMetadata, string $field): bool
    {
        return $targetMetadata->hasAssociation($field) || $targetMetadata->hasField($field);
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function synchronizeCollectionCounterpart(
        ClassMetadata $targetMetadata,
        object $item,
        string $counterpartField,
        object $entity,
        bool $adding,
    ): void {
        $counterpartValue = $targetMetadata->getFieldValue($item, $counterpartField);
        if (!$counterpartValue instanceof Collection) {
            return;
        }

        if ($adding) {
            if (!$counterpartValue->contains($entity)) {
                $counterpartValue->add($entity);
            }

            return;
        }

        $counterpartValue->removeElement($entity);
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function synchronizeSingleValuedCounterpart(
        ClassMetadata $targetMetadata,
        object $item,
        string $counterpartField,
        object $entity,
        bool $adding,
    ): void {
        $targetMetadata->setFieldValue($item, $counterpartField, $adding ? $entity : null);
    }

    private function invokeCounterpartMutator(object $entity, object $item, bool $adding): bool
    {
        $shortName = new ReflectionClass($entity)->getShortName();
        $shortName = $this->normalizeShortClassName($shortName);
        $method = ($adding ? 'add' : 'remove').$shortName;

        if (!method_exists($item, $method)) {
            return false;
        }

        $reflectionMethod = new ReflectionMethod($item, $method);
        if (!$reflectionMethod->isPublic()) {
            return false;
        }

        $reflectionMethod->invoke($item, $entity);

        return true;
    }

    private function normalizeShortClassName(string $shortName): string
    {
        $normalized = preg_replace('/@anonymous.*$/', '', $shortName);

        return $normalized !== null && $normalized !== '' ? $normalized : 'Item';
    }
}
