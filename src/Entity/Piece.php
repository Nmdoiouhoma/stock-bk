<?php

namespace App\Entity;

use App\Enum\PieceType;
use App\Repository\PieceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PieceRepository::class)]
class Piece
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?float $salePrice = null;

    #[ORM\Column(type: 'string', enumType: PieceType::class)]
    private ?PieceType $type = null;

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

    public function getSalePrice(): ?float
    {
        return $this->salePrice;
    }

    public function setSalePrice(float $salePrice): static
    {
        $this->salePrice = $salePrice;

        return $this;
    }

    public function getType(): ?PieceType
    {
        return $this->type;
    }

    public function setType(PieceType $type): static
    {
        $this->type = $type;

        return $this;
    }
}
