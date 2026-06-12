<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

/**
 * Owner side of a ManyToMany whose target entity ({@see Membership}) uses class
 * inheritance. Reproduces the crash where reading this collection through
 * Doctrine's Criteria fast-path fails with
 * "ResultSetMappingBuilder does not currently support your inheritance scheme.".
 */
#[ORM\Entity]
#[ORM\Table(name: 'club')]
#[Auditable]
class Club
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType
    private ?int $id = null;

    #[ORM\Column]
    private string $name;

    /**
     * @var Collection<int, Membership>
     */
    #[ORM\ManyToMany(targetEntity: Membership::class, inversedBy: 'clubs', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'club_membership')]
    private Collection $memberships;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->memberships = new ArrayCollection();
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
     * @return Collection<int, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(Membership $membership): void
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->addClub($this);
        }
    }

    public function removeMembership(Membership $membership): void
    {
        if ($this->memberships->removeElement($membership)) {
            $membership->removeClub($this);
        }
    }
}

/**
 * Inherited (JOINED) target of the {@see Club} ManyToMany association.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'discr', type: 'string')]
#[ORM\DiscriminatorMap(['membership' => Membership::class, 'premium' => PremiumMembership::class])]
#[Auditable]
abstract class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType
    private ?int $id = null;

    #[ORM\Column]
    private string $label;

    /**
     * @var Collection<int, Club>
     */
    #[ORM\ManyToMany(targetEntity: Club::class, mappedBy: 'memberships')]
    private Collection $clubs;

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->clubs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * @return Collection<int, Club>
     */
    public function getClubs(): Collection
    {
        return $this->clubs;
    }

    public function addClub(Club $club): void
    {
        if (!$this->clubs->contains($club)) {
            $this->clubs->add($club);
        }
    }

    public function removeClub(Club $club): void
    {
        $this->clubs->removeElement($club);
    }
}

#[ORM\Entity]
class PremiumMembership extends Membership
{
    #[ORM\Column(nullable: true)]
    private ?string $tier = null;

    public function getTier(): ?string
    {
        return $this->tier;
    }

    public function setTier(string $tier): void
    {
        $this->tier = $tier;
    }
}
