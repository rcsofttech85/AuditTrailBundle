<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

use function array_fill_keys;
use function array_is_list;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function max;

final class ChangeProcessor implements ChangeProcessorInterface
{
    public function __construct(
        private readonly AuditMetadataManagerInterface $metadataManager,
        private readonly ValueSerializerInterface $serializer,
        private readonly bool $enableSoftDelete = true,
        private readonly string $softDeleteField = 'deletedAt',
    ) {
    }

    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    #[Override]
    public function extractChanges(object $entity, array $changeSet): array
    {
        $old = [];
        $new = [];
        $sensitiveFields = $this->metadataManager->getSensitiveFields($entity::class);
        $ignoredFieldLookup = array_fill_keys($this->metadataManager->getIgnoredProperties($entity), true);

        foreach ($changeSet as $field => $change) {
            if (isset($ignoredFieldLookup[$field])) {
                continue;
            }

            $normalizedChange = $this->normalizeChangeTuple($change);
            if ($normalizedChange === null) {
                continue;
            }

            [$oldValue, $newValue] = $normalizedChange;

            if (!$this->valuesAreDifferent($oldValue, $newValue)) {
                continue;
            }

            if (isset($sensitiveFields[$field])) {
                $old[$field] = $sensitiveFields[$field];
                $new[$field] = $sensitiveFields[$field];
            } else {
                $old[$field] = $this->serializer->serialize($oldValue);
                $new[$field] = $this->serializer->serialize($newValue);
            }
        }

        return [$old, $new];
    }

    private function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue === null || $newValue === null) {
            return $oldValue !== $newValue;
        }

        if ($this->isNativeNumeric($oldValue) && $this->isNativeNumeric($newValue)) {
            $oldFloat = (float) $oldValue;
            $newFloat = (float) $newValue;
            $difference = abs($oldFloat - $newFloat);
            $relativeTolerance = 1e-9 * max(1.0, abs($oldFloat), abs($newFloat));

            return $difference > $relativeTolerance;
        }

        return $oldValue !== $newValue;
    }

    private function isNativeNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    #[Override]
    public function determineUpdateAction(array $changeSet): AuditAction
    {
        $softDeleteChange = $this->resolveSoftDeleteChange($changeSet);
        if ($softDeleteChange === null) {
            return AuditAction::Update;
        }

        [$oldValue, $newValue] = $softDeleteChange;

        return $this->resolveSoftDeleteAction($oldValue, $newValue) ?? AuditAction::Update;
    }

    #[Override]
    public function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $enableHardDelete): ?AuditAction
    {
        $softDeleteAction = $this->resolveDeletionSoftDeleteAction($em, $entity);
        if ($softDeleteAction !== null) {
            return $softDeleteAction;
        }

        return $enableHardDelete ? AuditAction::Delete : null;
    }

    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{0: mixed, 1: mixed}|null
     */
    private function resolveSoftDeleteChange(array $changeSet): ?array
    {
        if (!$this->enableSoftDelete) {
            return null;
        }

        return $this->normalizeChangeTuple($changeSet[$this->softDeleteField] ?? null);
    }

    private function resolveSoftDeleteAction(mixed $oldValue, mixed $newValue): ?AuditAction
    {
        if ($oldValue === null && $newValue !== null) {
            return AuditAction::SoftDelete;
        }

        if ($oldValue !== null && $newValue === null) {
            return AuditAction::Restore;
        }

        return null;
    }

    private function resolveDeletionSoftDeleteAction(EntityManagerInterface $em, object $entity): ?AuditAction
    {
        if (!$this->enableSoftDelete) {
            return null;
        }

        $meta = $em->getClassMetadata($entity::class);
        if (!$meta->hasField($this->softDeleteField)) {
            return null;
        }

        $changeSet = $em->getUnitOfWork()->getEntityChangeSet($entity);
        $softDeleteChange = $this->normalizeChangeTuple($changeSet[$this->softDeleteField] ?? null);
        if ($softDeleteChange === null) {
            return null;
        }

        return $this->resolveSoftDeleteAction($softDeleteChange[0], $softDeleteChange[1]);
    }

    /**
     * @return array{0: mixed, 1: mixed}|null
     */
    private function normalizeChangeTuple(mixed $change): ?array
    {
        if (!is_array($change) || !array_is_list($change) || count($change) < 2) {
            return null;
        }

        return [$change[0], $change[1]];
    }
}
