<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

/**
 * Owner of an EXTRA_LAZY inverse one-to-many collection.
 *
 * EXTRA_LAZY is the relevant detail: on an uninitialized collection
 * `count()` issues a fresh `SELECT COUNT(*)`, but once the collection is
 * initialized `count()` returns the frozen in-memory size. This lets a test
 * observe whether the audit pass wrongly initialized the collection mid-flush.
 */
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
     * Returns the live collection so a test can mutate it via the collection
     * API (`add()`) without triggering initialization the way a `contains()`
     * guard would.
     *
     * @return Collection<int, LazyChild>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}
