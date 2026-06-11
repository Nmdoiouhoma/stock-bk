<?php

namespace App\Entity;

use App\Repository\BillOfMaterialsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BillOfMaterialsRepository::class)]
class BillOfMaterials
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'parentBoms')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Part $parentPart = null;

    #[ORM\ManyToOne(inversedBy: 'childBoms')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Part $childPart = null;

    #[ORM\Column(type: 'integer')]
    private ?int $quantity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentPart(): ?Part
    {
        return $this->parentPart;
    }

    public function setParentPart(?Part $parentPart): static
    {
        $this->parentPart = $parentPart;

        return $this;
    }

    public function getChildPart(): ?Part
    {
        return $this->childPart;
    }

    public function setChildPart(?Part $childPart): static
    {
        $this->childPart = $childPart;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }
}
