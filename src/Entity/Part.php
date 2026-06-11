<?php

namespace App\Entity;

use App\Enum\PieceType;
use App\Repository\PartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartRepository::class)]
class Part
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(type: 'string', enumType: PieceType::class)]
    private ?PieceType $type = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $salePrice = null;

    #[ORM\Column(type: 'integer')]
    private int $stockQuantity = 0;

    #[ORM\Column(type: 'integer')]
    private int $stockMin = 0;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Supplier $supplier = null;

    #[ORM\OneToMany(mappedBy: 'part', targetEntity: Routing::class)]
    private Collection $routings;

    #[ORM\OneToMany(mappedBy: 'parentPart', targetEntity: BillOfMaterials::class)]
    private Collection $parentBoms;

    #[ORM\OneToMany(mappedBy: 'childPart', targetEntity: BillOfMaterials::class)]
    private Collection $childBoms;

    public function __construct()
    {
        $this->routings = new ArrayCollection();
        $this->parentBoms = new ArrayCollection();
        $this->childBoms = new ArrayCollection();
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

    public function getType(): ?PieceType
    {
        return $this->type;
    }

    public function setType(PieceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSalePrice(): ?float
    {
        return $this->salePrice;
    }

    public function setSalePrice(?float $salePrice): static
    {
        $this->salePrice = $salePrice;

        return $this;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function setStockQuantity(int $stockQuantity): static
    {
        $this->stockQuantity = $stockQuantity;

        return $this;
    }

    public function getStockMin(): int
    {
        return $this->stockMin;
    }

    public function setStockMin(int $stockMin): static
    {
        $this->stockMin = $stockMin;

        return $this;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;

        return $this;
    }

    /**
     * @return Collection<int, Routing>
     */
    public function getRoutings(): Collection
    {
        return $this->routings;
    }

    /**
     * @return Collection<int, BillOfMaterials>
     */
    public function getParentBoms(): Collection
    {
        return $this->parentBoms;
    }

    /**
     * @return Collection<int, BillOfMaterials>
     */
    public function getChildBoms(): Collection
    {
        return $this->childBoms;
    }
}
