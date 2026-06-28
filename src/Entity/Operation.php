<?php

namespace App\Entity;

use App\Repository\OperationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OperationRepository::class)]
class Operation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $rank = 1;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(type: 'float')]
    private float $unitTime = 0.0;

    #[ORM\ManyToMany(targetEntity: Routing::class, inversedBy: 'operations')]
    #[ORM\JoinTable(name: 'operation_routing')]
    private Collection $routings;

    #[ORM\ManyToOne(inversedBy: 'operations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Workstation $workstation = null;

    #[ORM\ManyToOne(inversedBy: 'operations')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Machine $machine = null;

    #[ORM\OneToMany(mappedBy: 'operation', targetEntity: ProductionOrder::class)]
    private Collection $productionOrders;

    public function __construct()
    {
        $this->routings       = new ArrayCollection();
        $this->productionOrders = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function setRank(int $rank): static
    {
        $this->rank = $rank;

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

    public function getUnitTime(): float
    {
        return $this->unitTime;
    }

    public function setUnitTime(float $unitTime): static
    {
        $this->unitTime = $unitTime;

        return $this;
    }

    /**
     * @return Collection<int, Routing>
     */
    public function getRoutings(): Collection
    {
        return $this->routings;
    }

    public function addRouting(Routing $routing): static
    {
        if (!$this->routings->contains($routing)) {
            $this->routings->add($routing);
        }

        return $this;
    }

    public function removeRouting(Routing $routing): static
    {
        $this->routings->removeElement($routing);

        return $this;
    }

    public function getWorkstation(): ?Workstation
    {
        return $this->workstation;
    }

    public function setWorkstation(?Workstation $workstation): static
    {
        $this->workstation = $workstation;

        return $this;
    }

    public function getMachine(): ?Machine
    {
        return $this->machine;
    }

    public function setMachine(?Machine $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    /**
     * @return Collection<int, ProductionOrder>
     */
    public function getProductionOrders(): Collection
    {
        return $this->productionOrders;
    }
}
