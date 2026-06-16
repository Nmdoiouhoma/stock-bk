<?php

namespace App\Entity;

use App\Repository\RoutingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoutingRepository::class)]
class Routing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\ManyToOne(inversedBy: 'routings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Part $part = null;

    #[ORM\ManyToOne(inversedBy: 'routings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $supervisor = null;

    #[ORM\OneToMany(mappedBy: 'routing', targetEntity: Operation::class)]
    private Collection $operations;

    public function __construct()
    {
        $this->operations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPart(): ?Part
    {
        return $this->part;
    }

    public function setPart(?Part $part): static
    {
        $this->part = $part;

        return $this;
    }

    public function getSupervisor(): ?User
    {
        return $this->supervisor;
    }

    public function setSupervisor(?User $supervisor): static
    {
        $this->supervisor = $supervisor;

        return $this;
    }

    /**
     * @return Collection<int, Operation>
     */
    public function getOperations(): Collection
    {
        return $this->operations;
    }
}
