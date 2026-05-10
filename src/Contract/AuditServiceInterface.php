<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

interface AuditServiceInterface
{
    /**
     * @param array<string, mixed> $changeSet
     */
    public function shouldAudit(
        object $entity,
        AuditAction $action = AuditAction::Create,
        array $changeSet = [],
    ): bool;

    /**
     * @param array<string, mixed> $changeSet
     */
    public function passesVoters(object $entity, AuditAction $action, array $changeSet = []): bool;

    public function getAccessAttribute(string $class): ?AuditAccess;

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string, mixed>
     */
    public function getEntityData(
        object $entity,
        array $additionalIgnored = [],
        ?EntityManagerInterface $entityManager = null,
    ): array;

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>      $context
     */
    public function createAuditLog(
        object $entity,
        AuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
        ?EntityManagerInterface $entityManager = null,
    ): AuditLog;

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(object $entity): array;
}
