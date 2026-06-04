<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

/**
 * Owning side of the {@see LazyParent} EXTRA_LAZY one-to-many. Setting the
 * parent through this side does not touch the inverse collection in memory.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lazy_child')]
#[Auditable]
class LazyChild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private string $name,
        #[ORM\ManyToOne(targetEntity: LazyParent::class, inversedBy: 'children')]
        #[ORM\JoinColumn(nullable: false)]
        private LazyParent $parent,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): LazyParent
    {
        return $this->parent;
    }
}
