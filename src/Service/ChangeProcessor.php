<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

class ChangeProcessor
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly bool $enableSoftDelete = true,
        private readonly string $softDeleteField = 'deletedAt',
    ) {
    }

    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractChanges(object $entity, array $changeSet): array
    {
        $old = [];
        $new = [];
        $sensitiveFields = $this->auditService->getSensitiveFields($entity);
        $ignored = $this->auditService->getIgnoredProperties($entity);

        foreach ($changeSet as $field => $change) {
            if (\in_array($field, $ignored, true)) {
                continue;
            }

            if (!is_array($change) || !array_key_exists(0, $change) || !array_key_exists(1, $change)) {
                continue;
            }

            [$oldValue, $newValue] = $change;

            if ($oldValue === $newValue) {
                continue;
            }

            if (isset($sensitiveFields[$field])) {
                $old[$field] = $sensitiveFields[$field];
                $new[$field] = $sensitiveFields[$field];
            } else {
                $old[$field] = $oldValue;
                $new[$field] = $newValue;
            }
        }

        return [$old, $new];
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    public function determineUpdateAction(array $changeSet): string
    {
        if (!$this->enableSoftDelete || !\array_key_exists($this->softDeleteField, $changeSet)) {
            return AuditLogInterface::ACTION_UPDATE;
        }

        [$oldValue, $newValue] = $changeSet[$this->softDeleteField];

        return (null !== $oldValue && null === $newValue)
            ? AuditLogInterface::ACTION_RESTORE
            : AuditLogInterface::ACTION_UPDATE;
    }

    public function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $enableHardDelete): ?string
    {
        if ($this->enableSoftDelete) {
            $meta = $em->getClassMetadata($entity::class);
            if ($meta->hasField($this->softDeleteField)) {
                $reflProp = $meta->getReflectionProperty($this->softDeleteField);
                $softDeleteValue = $reflProp?->getValue($entity);
                if (null !== $softDeleteValue) {
                    return AuditLogInterface::ACTION_SOFT_DELETE;
                }
            }
        }

        return $enableHardDelete ? AuditLogInterface::ACTION_DELETE : null;
    }
}
