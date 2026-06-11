<?php

namespace App\Entity;

use App\Repository\CompletionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompletionRepository::class)]
class Completion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: 'integer')]
    private int $actualQuantity = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $actualDuration = null;

    #[ORM\ManyToOne(inversedBy: 'completions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Operation $operation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getActualQuantity(): int
    {
        return $this->actualQuantity;
    }

    public function setActualQuantity(int $actualQuantity): static
    {
        $this->actualQuantity = $actualQuantity;

        return $this;
    }

    public function getActualDuration(): ?float
    {
        return $this->actualDuration;
    }

    public function setActualDuration(?float $actualDuration): static
    {
        $this->actualDuration = $actualDuration;

        return $this;
    }

    public function getOperation(): ?Operation
    {
        return $this->operation;
    }

    public function setOperation(?Operation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }
}
