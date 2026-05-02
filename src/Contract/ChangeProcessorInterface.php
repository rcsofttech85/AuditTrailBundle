<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

interface ChangeProcessorInterface
{
    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractChanges(object $entity, array $changeSet): array;

    /**
     * @param array<string, mixed> $changeSet
     */
    public function determineUpdateAction(array $changeSet): AuditAction;

    public function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $enableHardDelete): ?AuditAction;
}
