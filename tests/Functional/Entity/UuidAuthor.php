<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'uuid_author')]
#[Auditable]
class UuidAuthor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $name;

    /**
     * @var Collection<int, UuidTag>
     */
    #[ORM\ManyToMany(targetEntity: UuidTag::class, inversedBy: 'authors', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'uuid_author_tag')]
    private Collection $tags;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return Collection<int, UuidTag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(UuidTag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $tag->addAuthor($this);
        }
    }

    public function removeTag(UuidTag $tag): void
    {
        if ($this->tags->removeElement($tag)) {
            $tag->removeAuthor($this);
        }
    }
}
