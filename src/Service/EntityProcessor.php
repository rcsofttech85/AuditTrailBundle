<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;

final readonly class EntityProcessor implements EntityProcessorInterface
{
    public function __construct(
        private EntityInsertionProcessor $insertionProcessor,
        private EntityUpdateProcessor $updateProcessor,
        private EntityCollectionUpdateProcessor $collectionUpdateProcessor,
        private EntityDeletionProcessor $deletionProcessor,
    ) {
    }

    #[Override]
    public function processInsertions(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        $this->insertionProcessor->process($em, $uow);
    }

    #[Override]
    /**
     * @param list<AssociationImpact>|null $deletedAssociationImpacts
     */
    public function processUpdates(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $this->updateProcessor->process($em, $uow, $deletedAssociationImpacts);
    }

    /**
     * @param iterable<object> $collectionUpdates
     */
    #[Override]
    public function processCollectionUpdates(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        iterable $collectionUpdates,
    ): void {
        $this->collectionUpdateProcessor->process($em, $uow, $collectionUpdates);
    }

    #[Override]
    /**
     * @param list<AssociationImpact>|null $deletedAssociationImpacts
     */
    public function processDeletions(EntityManagerInterface $em, UnitOfWork $uow, ?array $deletedAssociationImpacts = null): void
    {
        $this->deletionProcessor->process($em, $uow, $deletedAssociationImpacts);
    }
}
