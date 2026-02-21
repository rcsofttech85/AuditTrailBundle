<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;

interface ChangeProcessorInterface
{
    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extractChanges(object $entity, array $changeSet): array;

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    public function determineUpdateAction(array $changeSet): string;

    public function determineDeletionAction(EntityManagerInterface $em, object $entity, bool $enableHardDelete): ?string;
}
