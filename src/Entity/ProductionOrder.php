<?php

namespace App\Entity;

use App\Enum\OperationStatus;
use App\Repository\ProductionOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductionOrderRepository::class)]
class ProductionOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $plannedDate = null;

    #[ORM\Column(type: 'integer')]
    private int $plannedQuantity = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $actualQuantity = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedDuration = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $actualDuration = null;

    #[ORM\Column(type: 'string', enumType: OperationStatus::class)]
    private OperationStatus $status = OperationStatus::PENDING;

    #[ORM\ManyToOne(inversedBy: 'productionOrders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Operation $operation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlannedDate(): ?\DateTimeInterface
    {
        return $this->plannedDate;
    }

    public function setPlannedDate(\DateTimeInterface $plannedDate): static
    {
        $this->plannedDate = $plannedDate;

        return $this;
    }

    public function getPlannedQuantity(): int
    {
        return $this->plannedQuantity;
    }

    public function setPlannedQuantity(int $plannedQuantity): static
    {
        $this->plannedQuantity = $plannedQuantity;

        return $this;
    }

    public function getActualQuantity(): ?int
    {
        return $this->actualQuantity;
    }

    public function setActualQuantity(?int $actualQuantity): static
    {
        $this->actualQuantity = $actualQuantity;

        return $this;
    }

    public function getEstimatedDuration(): ?float
    {
        return $this->estimatedDuration;
    }

    public function setEstimatedDuration(?float $estimatedDuration): static
    {
        $this->estimatedDuration = $estimatedDuration;

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

    public function getStatus(): OperationStatus
    {
        return $this->status;
    }

    public function setStatus(OperationStatus $status): static
    {
        $this->status = $status;

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
