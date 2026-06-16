<?php

namespace App\Entity;

use App\Enum\PieceType;
use App\Repository\PartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: PartRepository::class)]
#[UniqueEntity(fields: ['reference'], message: 'Cette référence est déjà utilisée.')]
class Part
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    #[ORM\Column(type: 'string', enumType: PieceType::class)]
    #[Assert\NotNull]
    private ?PieceType $type = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $salePrice = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $stockQuantity = 0;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Supplier $supplier = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $catalogPrice = null;

    #[ORM\OneToMany(mappedBy: 'part', targetEntity: Routing::class)]
    private Collection $routings;

    #[ORM\OneToMany(mappedBy: 'parentPart', targetEntity: BillOfMaterials::class)]
    private Collection $parentBoms;

    #[ORM\OneToMany(mappedBy: 'childPart', targetEntity: BillOfMaterials::class)]
    private Collection $childBoms;

    #[Assert\Callback]
    public function validateByType(ExecutionContextInterface $context): void
    {
        if ($this->type === null) {
            return;
        }

        $manufactured = [PieceType::Finished, PieceType::Intermediate];
        $bought = [PieceType::RawMaterial, PieceType::Purchased];

        if ($this->type === PieceType::Finished && $this->salePrice === null) {
            $context->buildViolation('Le prix de vente est obligatoire pour une pièce finie.')
                ->atPath('salePrice')
                ->addViolation();
        }

        if (in_array($this->type, $manufactured, true) && $this->catalogPrice !== null) {
            $context->buildViolation('Le prix catalogue doit être vide pour une pièce fabriquée.')
                ->atPath('catalogPrice')
                ->addViolation();
        }

        if (in_array($this->type, $manufactured, true) && $this->supplier !== null) {
            $context->buildViolation('Un fournisseur ne peut pas être associé à une pièce fabriquée.')
                ->atPath('supplier')
                ->addViolation();
        }

        if ($this->type === PieceType::Intermediate && $this->salePrice !== null) {
            $context->buildViolation('Une pièce intermédiaire ne peut pas avoir de prix de vente.')
                ->atPath('salePrice')
                ->addViolation();
        }

        if (in_array($this->type, $bought, true) && $this->catalogPrice === null) {
            $context->buildViolation('Le prix catalogue est obligatoire pour une pièce achetée.')
                ->atPath('catalogPrice')
                ->addViolation();
        }

        if (in_array($this->type, $bought, true) && $this->supplier === null) {
            $context->buildViolation('Le fournisseur est obligatoire pour une pièce achetée.')
                ->atPath('supplier')
                ->addViolation();
        }

        if (in_array($this->type, $bought, true) && !$this->routings->isEmpty()) {
            $context->buildViolation('Une pièce achetée ne peut pas avoir de gamme de fabrication.')
                ->atPath('routings')
                ->addViolation();
        }
    }

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

    public function getCatalogPrice(): ?float
    {
        return $this->catalogPrice;
    }

    public function setCatalogPrice(?float $catalogPrice): static
    {
        $this->catalogPrice = $catalogPrice;

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
