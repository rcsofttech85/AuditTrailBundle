<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;

use function is_iterable;
use function is_object;

final readonly class CollectionIdExtractor
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
    ) {
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return array<int, int|string>
     */
    public function extractFromIterable(iterable $items, EntityManagerInterface $em): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            $id = $this->idResolver->resolveFromEntity($item, $em);
            if ($id !== AuditLogInterface::PENDING_ID) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function hasPendingIds(mixed $items, EntityManagerInterface $em): bool
    {
        if (!is_iterable($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            if ($this->idResolver->resolveFromEntity($item, $em) === AuditLogInterface::PENDING_ID) {
                return true;
            }
        }

        return false;
    }
}
