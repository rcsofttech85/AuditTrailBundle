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
use function array_key_exists;
use function is_array;
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

            if (!is_array($change) || !array_key_exists(0, $change) || !array_key_exists(1, $change)) {
                continue;
            }

            [$oldValue, $newValue] = $change;

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

        if (is_numeric($oldValue) && is_numeric($newValue)) {
            $oldFloat = (float) $oldValue;
            $newFloat = (float) $newValue;
            $difference = abs($oldFloat - $newFloat);
            $relativeTolerance = 1e-9 * max(1.0, abs($oldFloat), abs($newFloat));

            return $difference > $relativeTolerance;
        }

        return $oldValue !== $newValue;
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    #[Override]
    public function determineUpdateAction(array $changeSet): AuditAction
    {
        if (!$this->enableSoftDelete || !array_key_exists($this->softDeleteField, $changeSet)) {
            return AuditAction::Update;
        }

        $softDeleteChange = $changeSet[$this->softDeleteField];
        if (
            !is_array($softDeleteChange)
            || !array_key_exists(0, $softDeleteChange)
            || !array_key_exists(1, $softDeleteChange)
        ) {
            return AuditAction::Update;
        }

        [$oldValue, $newValue] = $softDeleteChange;
        if ($oldValue === null && $newValue !== null) {
            return AuditAction::SoftDelete;
        }

        return ($oldValue !== null && $newValue === null)
            ? AuditAction::Restore
            : AuditAction::Update;
    }

    #[Override]
    public function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $enableHardDelete): ?AuditAction
    {
        if (!$this->enableSoftDelete) {
            return $enableHardDelete ? AuditAction::Delete : null;
        }

        $meta = $em->getClassMetadata($entity::class);
        if ($meta->hasField($this->softDeleteField)) {
            $changeSet = $em->getUnitOfWork()->getEntityChangeSet($entity);
            $softDeleteChange = $changeSet[$this->softDeleteField] ?? null;

            if (is_array($softDeleteChange)) {
                [$oldValue, $newValue] = $softDeleteChange;

                if ($oldValue === null && $newValue !== null) {
                    return AuditAction::SoftDelete;
                }
            }
        }

        return $enableHardDelete ? AuditAction::Delete : null;
    }
}
