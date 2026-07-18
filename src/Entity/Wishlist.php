<?php

namespace App\Entity;

use App\Repository\WishlistRepository;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WishlistRepository::class)]
class Wishlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\ManyToOne(inversedBy: 'wishlists')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column]
    private ?\DateTime $updatedAt = null;

    #[ORM\Column(type: Types::NUMBER)]
    private ?Number $priceAtAdd = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    private ?array $variant = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $priceDropNotifiedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

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

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getPriceAtAdd(): ?Number
    {
        return $this->priceAtAdd;
    }

    public function setPriceAtAdd(Number $priceAtAdd): static
    {
        $this->priceAtAdd = $priceAtAdd;

        return $this;
    }

    public function getVariant(): ?array
    {
        return $this->variant;
    }

    public function setVariant(?array $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getPriceDropNotifiedAt(): ?\DateTime
    {
        return $this->priceDropNotifiedAt;
    }

    public function setPriceDropNotifiedAt(?\DateTime $priceDropNotifiedAt): static
    {
        $this->priceDropNotifiedAt = $priceDropNotifiedAt;

        return $this;
    }
}