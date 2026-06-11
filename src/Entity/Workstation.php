<?php

namespace App\Entity;

use App\Repository\WorkstationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkstationRepository::class)]
class Workstation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacity = null;

    #[ORM\OneToMany(mappedBy: 'workstation', targetEntity: Machine::class)]
    private Collection $machines;

    #[ORM\OneToMany(mappedBy: 'workstation', targetEntity: Operation::class)]
    private Collection $operations;

    public function __construct()
    {
        $this->machines = new ArrayCollection();
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

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(?int $capacity): static
    {
        $this->capacity = $capacity;

        return $this;
    }

    /**
     * @return Collection<int, Machine>
     */
    public function getMachines(): Collection
    {
        return $this->machines;
    }

    /**
     * @return Collection<int, Operation>
     */
    public function getOperations(): Collection
    {
        return $this->operations;
    }
}
