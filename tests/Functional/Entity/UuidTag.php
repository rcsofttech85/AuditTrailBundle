<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'uuid_tag')]
#[Auditable]
final class UuidTag
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\Column]
    private string $label;

    /**
     * @var Collection<int, UuidAuthor>
     */
    #[ORM\ManyToMany(targetEntity: UuidAuthor::class, mappedBy: 'tags')]
    private Collection $authors;

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->authors = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return Collection<int, UuidAuthor>
     */
    public function getAuthors(): Collection
    {
        return $this->authors;
    }

    public function addAuthor(UuidAuthor $author): void
    {
        if (!$this->authors->contains($author)) {
            $this->authors->add($author);
        }
    }

    public function removeAuthor(UuidAuthor $author): void
    {
        $this->authors->removeElement($author);
    }
}
