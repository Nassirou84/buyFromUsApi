<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use App\Controller\RemoveProductFromWhistlistController;
use App\Repository\WishlistRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\Get(),
        new \ApiPlatform\Metadata\GetCollection(
            uriTemplate: '/customer/{customerId}/wishlists',
            uriVariables: [
                'customerId' => new Link(
                    fromClass: User::class,
                    fromProperty: 'wishlists',
                ),
            ],
        ),
        new \ApiPlatform\Metadata\Post(
            security: "is_granted('ROLE_USER')",
            controller: RemoveProductFromWhistlistController::class,
            uriTemplate: '/wishlist/remove-product',
        ),
        new \ApiPlatform\Metadata\Post(
            security: "is_granted('ROLE_USER')",
            processor: \App\State\WishlistProcessor::class,
        ),
        new \ApiPlatform\Metadata\Delete(),
    ],
    normalizationContext: ['groups' => ['wishlist:read']],
    denormalizationContext: ['groups' => ['wishlist:write']],
)]
#[ORM\Entity(repositoryClass: WishlistRepository::class)]
class Wishlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['wishlist:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['wishlist:read', 'wishlist:write'])]
    private ?Product $product = null;

    #[ORM\ManyToOne(inversedBy: 'wishlists')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column]
    #[Groups(['wishlist:read'])]
    private ?DateTime $createdAt = null;

    #[ORM\Column]
    #[Groups(['wishlist:read'])]
    private ?DateTime $updatedAt = null;

    #[ORM\Column()]
    #[Groups(['wishlist:read'])]
    private ?float $priceAtAdd = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    #[Groups(['wishlist:read'])]
    private ?array $variant = null;

    #[Groups(['wishlist:read'])]
    #[ORM\Column(nullable: true)]
    private ?DateTime $priceDropNotifiedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
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

    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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

    public function getVariant(): ?array
    {
        return $this->variant;
    }

    public function setVariant(?array $variant): static
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
}