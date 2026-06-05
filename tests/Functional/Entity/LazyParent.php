<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'lazy_parent')]
#[Auditable]
class LazyParent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var Collection<int, LazyChild>
     */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: LazyChild::class, cascade: ['persist'], fetch: 'EXTRA_LAZY')]
    private Collection $children;

    public function __construct(
        #[ORM\Column]
        private string $name,
    ) {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return Collection<int, LazyChild>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}
