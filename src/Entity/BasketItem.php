<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BasketItemRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: BasketItemRepository::class)]
#[Groups(['basket:read'])]
class BasketItem implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?DateTime $createdAt = null;

    #[ORM\Column]
    private ?float $priceAtAdd = null;

    /**
     * The whole selected variant object (title, image, price, ...), as stored on the product.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $variant = null;

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

    /**
     * @return array<string, mixed>|null
     */
    public function getVariant(): ?array
    {
        return $this->variant;
    }

    /**
     * @param array<string, mixed>|null $variant
     */
    public function setVariant(?array $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getVariantLabel(): ?string
    {
        return Product::getVariantLabel($this->variant);
    }

    /**
     * Whether this item holds the given variant (matched by title/name, both null meaning "no variant").
     *
     * @param array<string, mixed>|null $variant
     */
    public function isSameVariant(?array $variant): bool
    {
        $label = $this->getVariantLabel();
        $other = Product::getVariantLabel($variant);

        return null === $label || null === $other
            ? $label === $other
            : mb_strtolower($label) === mb_strtolower($other);
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

    /**
     * Current retail price of one unit, honouring the selected variant (same source as the price saved at add time).
     */
    public function getUnitPrice(): float
    {
        return (float) $this->product?->getActualPriceForVariant($this->variant);
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