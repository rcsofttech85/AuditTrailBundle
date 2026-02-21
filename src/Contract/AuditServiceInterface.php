<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditServiceInterface
{
    /**
     * @param array<string, mixed> $changeSet
     */
    public function shouldAudit(
        object $entity,
        string $action = AuditLogInterface::ACTION_CREATE,
        array $changeSet = [],
    ): bool;

    /**
     * @param array<string, mixed> $changeSet
     */
    public function passesVoters(object $entity, string $action, array $changeSet = []): bool;

    public function getAccessAttribute(string $class): ?AuditAccess;

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string, mixed>
     */
    public function getEntityData(object $entity, array $additionalIgnored = []): array;

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>      $context
     */
    public function createAuditLog(
        object $entity,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
    ): AuditLog;

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(object $entity): array;
}
