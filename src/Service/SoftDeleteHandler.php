<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;

final readonly class SoftDeleteHandler implements SoftDeleteHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
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

        $disabled = array_keys(array_filter(
            $filters->getEnabledFilters(),
            static fn ($filter) => str_contains($filter::class, 'SoftDeleteableFilter')
        ));

        array_walk($disabled, $filters->disable(...));

        return $disabled;
    }

    /**
     * @param array<string> $names
     */
    public function enableFilters(array $names): void
    {
        array_walk($names, $this->em->getFilters()->enable(...));
    }
}
