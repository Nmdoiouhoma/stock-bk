<?php

namespace App\Entity;

use App\Enum\ForecastStatus;
use App\Repository\ForecastRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForecastRepository::class)]
class Forecast
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $plannedDate = null;

    #[ORM\Column(type: 'integer')]
    private int $plannedQuantity = 0;

    #[ORM\Column(type: 'string', enumType: ForecastStatus::class)]
    private ForecastStatus $status = ForecastStatus::PENDING;

    #[ORM\ManyToOne(inversedBy: 'forecasts')]
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

    public function getStatus(): ForecastStatus
    {
        return $this->status;
    }

    public function setStatus(ForecastStatus $status): static
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
