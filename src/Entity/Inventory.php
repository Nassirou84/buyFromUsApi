<?php

namespace App\Entity;

use App\Repository\InventoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryRepository::class)]
class Inventory
{
    const MAIN_WAREHOUSE = 'main_warehouse';


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?OrderItem $item = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column]
    private ?bool $isAvailable = null;

    #[ORM\Column(length: 255)]
    private ?string $itemCondition = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY)]
    private array $packaging = [];

    #[ORM\ManyToOne(inversedBy: 'inventories')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hub $hub = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->isAvailable = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ?OrderItem
    {
        return $this->item;
    }

    public function setItem(OrderItem $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isAvailable(): ?bool
    {
        return $this->isAvailable;
    }

    public function setIsAvailable(bool $isAvailable): static
    {
        $this->isAvailable = $isAvailable;

        return $this;
    }

    public function getItemCondition(): ?string
    {
        return $this->itemCondition;
    }

    public function setItemCondition(string $itemCondition): static
    {
        $this->itemCondition = $itemCondition;

        return $this;
    }

    public function getPackaging(): array
    {
        return $this->packaging;
    }

    public function setPackaging(array $packaging): static
    {
        $this->packaging = $packaging;

        return $this;
    }

    public function getHub(): ?Hub
    {
        return $this->hub;
    }

    public function setHub(?Hub $hub): static
    {
        $this->hub = $hub;

        return $this;
    }
}