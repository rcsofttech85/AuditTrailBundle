<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;

interface TrackableCollectionInterface
{
    public function getOwner(): ?object;

    /**
     * @return array<int, object>
     */
    public function getInsertDiff(): array;

    /**
     * @return array<int, object>
     */
    public function getDeleteDiff(): array;

    public function getMapping(): AssociationMapping&ToManyAssociationMapping;

    /**
     * @return iterable<mixed>
     */
    public function getSnapshot(): iterable;
}
