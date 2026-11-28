<?php

namespace App\Entity;

use App\Repository\InventoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryRepository::class)]
class Inventory
{

    const TYPE_RETURN = 'return';
    const TYPE_SALE = 'sale';
    const TYPE_STOCK = 'stock';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    private ?ProductReturnItem $productReturnItem = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(length: 30)]
    private ?string $type = null;

    #[ORM\Column]
    private ?bool $isAvailable = null;

    #[ORM\Column(nullable: true)]
    private ?bool $canBeResold = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getProductReturnItem(): ?ProductReturnItem
    {
        return $this->productReturnItem;
    }

    public function setProductReturnItem(?ProductReturnItem $productReturnItem): static
    {
        $this->productReturnItem = $productReturnItem;

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

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function isCanBeResold(): ?bool
    {
        return $this->canBeResold;
    }

    public function setCanBeResold(?bool $canBeResold): static
    {
        $this->canBeResold = $canBeResold;

        return $this;
    }
}