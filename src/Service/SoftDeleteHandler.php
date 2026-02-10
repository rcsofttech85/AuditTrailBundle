<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

class SoftDeleteHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isSoftDeleted(object $entity): bool
    {
        return method_exists($entity, 'getDeletedAt') && null !== $entity->getDeletedAt();
    }

    public function restoreSoftDeleted(object $entity): void
    {
        if (method_exists($entity, 'setDeletedAt')) {
            $entity->setDeletedAt(null);
        }
    }

    /**
     * @return array<string>
     */
    public function disableSoftDeleteFilters(): array
    {
        $filters = $this->em->getFilters();
        $disabled = [];

        foreach ($filters->getEnabledFilters() as $name => $filter) {
            if (str_contains($filter::class, 'SoftDeleteableFilter')) {
                $filters->disable($name);
                $disabled[] = $name;
            }
        }

        return $disabled;
    }

    /**
     * @param array<string> $names
     */
    public function enableFilters(array $names): void
    {
        $filters = $this->em->getFilters();
        foreach ($names as $name) {
            $filters->enable($name);
        }
    }
}
