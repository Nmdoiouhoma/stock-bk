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

    #[ORM\ManyToMany(targetEntity: Machine::class, mappedBy: 'workstations')]
    private Collection $machines;

    #[ORM\OneToMany(mappedBy: 'workstation', targetEntity: Operation::class)]
    private Collection $operations;

    #[ORM\ManyToMany(mappedBy: 'workstations', targetEntity: User::class)]
    private Collection $qualifiedUsers;

    public function __construct()
    {
        $this->machines = new ArrayCollection();
        $this->operations = new ArrayCollection();
        $this->qualifiedUsers = new ArrayCollection();
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

    public function addMachine(Machine $machine): static
    {
        if (!$this->machines->contains($machine)) {
            $machine->addWorkstation($this);
        }

        return $this;
    }

    public function removeMachine(Machine $machine): static
    {
        $machine->removeWorkstation($this);

        return $this;
    }

    /**
     * @return Collection<int, Operation>
     */
    public function getOperations(): Collection
    {
        return $this->operations;
    }

    /**
     * @return Collection<int, User>
     */
    public function getQualifiedUsers(): Collection
    {
        return $this->qualifiedUsers;
    }

    public function addQualifiedUser(User $user): static
    {
        if (!$this->qualifiedUsers->contains($user)) {
            $this->qualifiedUsers->add($user);
        }

        return $this;
    }

    public function removeQualifiedUser(User $user): static
    {
        $this->qualifiedUsers->removeElement($user);

        return $this;
    }
}
