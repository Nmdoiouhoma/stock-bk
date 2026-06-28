<?php

namespace App\Entity;

use App\Enum\Role;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Quote;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255)]
    private ?string $lastname = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', enumType: Role::class)]
    private ?Role $role = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\OneToMany(mappedBy: 'supervisor', targetEntity: Routing::class)]
    private Collection $routings;

    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Quote::class)]
    private Collection $quotes;
    
    #[ORM\ManyToMany(targetEntity: Workstation::class, inversedBy: 'qualifiedUsers')]
    #[ORM\JoinTable(name: 'user_workstation')]
    private Collection $workstations;

    public function __construct()
    {
        $this->routings = new ArrayCollection();
        $this->workstations = new ArrayCollection();
        $this->quotes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(Role $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    // UserInterface

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        if ($this->role === null) {
            return ['ROLE_USER'];
        }

        return ['ROLE_' . strtoupper($this->role->value), 'ROLE_USER'];
    }

    /**
     * @return Collection<int, Routing>
     */
    public function getRoutings(): Collection
    {
        return $this->routings;
    }

    /**
     * @return Collection<int, Workstation>
     */
    public function getWorkstations(): Collection
    {
        return $this->workstations;
    }

    public function addWorkstation(Workstation $workstation): static
    {
        if (!$this->workstations->contains($workstation)) {
            $this->workstations->add($workstation);
        }

        return $this;
    }

    public function removeWorkstation(Workstation $workstation): static
    {
        $this->workstations->removeElement($workstation);

        return $this;
    }

    /**
     * @return Collection<int, Quote>
     */
    public function getQuotes(): Collection
    {
        return $this->quotes;
    }

    public function addQuote(Quote $quote): static
    {
        if (!$this->quotes->contains($quote)) {
            $this->quotes->add($quote);
            $quote->setClient($this);
        }

        return $this;
    }

    public function removeQuote(Quote $quote): static
    {
        if ($this->quotes->removeElement($quote)) {
            if ($quote->getClient() === $this) {
                $quote->setClient(null);
            }
        }

        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
