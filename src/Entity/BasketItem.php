<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BasketItemRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: BasketItemRepository::class)]
#[Groups(['basket:read'])]
class BasketItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?DateTime $createdAt = null;

    #[ORM\Column]
    private ?float $priceAtAdd = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variant = null;

    #[ORM\Column(nullable: true)]
    private ?DateTime $priceDropNotifiedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\ManyToOne(inversedBy: 'basketItems')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Basket $basket = null;

    #[ORM\Column]
    private ?int $quantity = null;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->priceDropNotifiedAt = new DateTime();
        $this->quantity = 1;
    }

    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPriceAtAdd(): ?float
    {
        return $this->priceAtAdd;
    }

    public function setPriceAtAdd(float $priceAtAdd): static
    {
        $this->priceAtAdd = $priceAtAdd;

        return $this;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    public function setVariant(?string $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getPriceDropNotifiedAt(): ?DateTime
    {
        return $this->priceDropNotifiedAt;
    }

    public function setPriceDropNotifiedAt(?DateTime $priceDropNotifiedAt): static
    {
        $this->priceDropNotifiedAt = $priceDropNotifiedAt;

        return $this;
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

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(?Basket $basket): static
    {
        $this->basket = $basket;

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

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'priceAtAdd' => $this->priceAtAdd,
            'variant' => $this->variant,
            'priceDropNotifiedAt' => $this->priceDropNotifiedAt?->format(\DateTimeInterface::ATOM),
            'product' => $this->product,
            'basket' => $this->basket?->getUid(),
            'quantity' => $this->quantity,
        ];
    }
}