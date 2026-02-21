<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;

interface EntityProcessorInterface
{
    public function processInsertions(EntityManagerInterface $em, UnitOfWork $uow): void;

    public function processUpdates(EntityManagerInterface $em, UnitOfWork $uow): void;

    public function processDeletions(EntityManagerInterface $em, UnitOfWork $uow): void;

    /**
     * @param iterable<object> $collectionUpdates
     */
    public function processCollectionUpdates(EntityManagerInterface $em, UnitOfWork $uow, iterable $collectionUpdates): void;
}
