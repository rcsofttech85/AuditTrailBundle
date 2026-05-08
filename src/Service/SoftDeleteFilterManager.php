<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

use function in_array;

final readonly class SoftDeleteFilterManager
{
    private const string GEDMO_SOFT_DELETE_FILTER = 'Gedmo\\SoftDeleteable\\Filter\\SoftDeleteableFilter';

    /**
     * @param list<string> $softDeleteFilterNames
     */
    public function __construct(
        private array $softDeleteFilterNames = ['softdeleteable'],
    ) {
    }

    /**
     * @return list<string>
     */
    public function disable(EntityManagerInterface $entityManager): array
    {
        $filters = $entityManager->getFilters();
        $disabled = [];

        foreach ($filters->getEnabledFilters() as $name => $filter) {
            if ($this->isManagedSoftDeleteFilter($name, $filter)) {
                $disabled[] = $name;
            }
        }

        foreach ($disabled as $name) {
            $filters->suspend($name);
        }

        return $disabled;
    }

    /**
     * @param list<string> $names
     */
    public function enable(EntityManagerInterface $entityManager, array $names): void
    {
        $filters = $entityManager->getFilters();

        foreach ($names as $name) {
            if ($filters->isSuspended($name)) {
                $filters->restore($name);

                continue;
            }

            $filters->enable($name);
        }
    }

    private function isManagedSoftDeleteFilter(string $name, object $filter): bool
    {
        if (in_array($name, $this->softDeleteFilterNames, true)) {
            return true;
        }

        return is_a($filter, self::GEDMO_SOFT_DELETE_FILTER);
    }
}
