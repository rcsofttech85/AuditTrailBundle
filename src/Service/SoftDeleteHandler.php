<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;

use function in_array;

final readonly class SoftDeleteHandler implements SoftDeleteHandlerInterface
{
    private const string GEDMO_SOFT_DELETE_FILTER = 'Gedmo\\SoftDeleteable\\Filter\\SoftDeleteableFilter';

    public function __construct(
        private EntityManagerInterface $em,
        /** @var list<string> */
        private array $softDeleteFilterNames = ['softdeleteable'],
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
            if ($this->isManagedSoftDeleteFilter($name, $filter)) {
                $disabled[] = $name;
            }
        }

        foreach ($disabled as $name) {
            $filters->disable($name);
        }

        return $disabled;
    }

    /**
     * @param array<string> $names
     */
    public function enableFilters(array $names): void
    {
        array_walk($names, $this->em->getFilters()->enable(...));
    }

    private function isManagedSoftDeleteFilter(string $name, object $filter): bool
    {
        if (in_array($name, $this->softDeleteFilterNames, true)) {
            return true;
        }

        return is_a($filter, self::GEDMO_SOFT_DELETE_FILTER);
    }
}
