<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

trait PendingIdResolver
{
    /**
     * @param array<string, mixed> $context
     */
    private function resolveEntityId(AuditLog $log, array $context): ?string
    {
        $isInsert = (bool) ($context['is_insert'] ?? false);
        $currentEntityId = $log->getEntityId();

        // If it's not an insert and not pending, we don't need to do anything
        if (!$isInsert && 'pending' !== $currentEntityId) {
            return null;
        }

        // If it's an insert but the ID is already set (not pending), return that
        if ($isInsert && 'pending' !== $currentEntityId) {
            return $currentEntityId;
        }

        // Otherwise, we need to resolve it from the entity
        $entity = $context['entity'] ?? null;
        if (!\is_object($entity)) {
            return null;
        }

        /** @var EntityManagerInterface $em */
        $em = $context['em'];
        $meta = $em->getClassMetadata($entity::class);
        $ids = $meta->getIdentifierValues($entity);

        if ([] === $ids) {
            return null;
        }

        return \count($ids) > 1
            ? json_encode(array_values($ids), JSON_THROW_ON_ERROR)
            : (string) reset($ids);
    }
}
