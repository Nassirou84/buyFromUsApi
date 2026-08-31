<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Repository\BasketRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/customer/{customerId}/baskets',
            uriVariables: [
                'customerId' => new Link(
                    fromClass: User::class,
                    fromProperty: 'basket',
                ),
            ],
            paginationEnabled: false,
            security: "is_granted('ROLE_USER')",
        ),
        new GetCollection(
            uriTemplate: '/basket/{uid}',
            uriVariables: [
                'uid' => new Link(
                    fromClass: Basket::class,
                    fromProperty: 'uid',
                ),
            ],
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            routeName: 'app_add_to_basket',
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            routeName: 'app_remove_from_basket',
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            routeName: 'app_update_basket_item_quantity',
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            routeName: 'app_clear_basket',
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            routeName: 'app_move_wishlist_to_basket',
        ),
    ],
    normalizationContext: ['groups' => ['basket:read']],
    denormalizationContext: ['groups' => ['basket:write']],
)]
#[ORM\Entity(repositoryClass: BasketRepository::class)]
class Basket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['basket:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id;

    #[ORM\OneToOne(inversedBy: 'basket', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\Column]
    #[Groups(['basket:read'])]
    private ?DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, BasketItem>
     */
    #[ORM\OneToMany(targetEntity: BasketItem::class, mappedBy: 'basket', orphanRemoval: true)]
    #[Groups(['basket:read'])]
    private Collection $basketItems;

    #[ORM\Column(length: 255)]
    #[Groups(['basket:read'])]
    private ?string $uid = null;

    public function __construct()
    {
        $this->basketItems = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, BasketItem>
     */
    public function getBasketItems(): Collection
    {
        return $this->basketItems;
    }

    public function setBasketItems(array $basketItems): static
    {
        $this->basketItems = new ArrayCollection($basketItems);

        return $this;
    }

    public function addBasketItem(BasketItem $basketItem): static
    {
        if (!$this->basketItems->contains($basketItem)) {
            $this->basketItems->add($basketItem);
            $basketItem->setBasket($this);
        }

        return $this;
    }

    public function removeBasketItem(BasketItem $basketItem): static
    {
        if ($this->basketItems->removeElement($basketItem)) {
            // set the owning side to null (unless already changed)
            if ($basketItem->getBasket() === $this) {
                $basketItem->setBasket(null);
            }
        }

        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string $uid): static
    {
        $this->uid = $uid;

        return $this;
    }
}