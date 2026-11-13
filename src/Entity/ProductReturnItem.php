<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductReturnItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Attribute\Groups;
use ApiPlatform\Metadata\ApiResource;

#[ORM\Entity(repositoryClass: ProductReturnItemRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['product_return:read']]
)]
class ProductReturnItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product_return:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'returnItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?ProductReturn $productReturn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['product_return:read'])]
    #[Assert\NotNull]
    private ?OrderItem $orderItem = null;

    #[ORM\Column]
    #[Groups(['product_return:read'])]
    #[Assert\Positive]
    private ?int $quantity = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product_return:read'])]
    #[Assert\NotBlank]
    private ?string $reason = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductReturn(): ?ProductReturn
    {
        return $this->productReturn;
    }

    public function setProductReturn(?ProductReturn $productReturn): static
    {
        $this->productReturn = $productReturn;

        return $this;
    }

    public function getOrderItem(): ?OrderItem
    {
        return $this->orderItem;
    }

    public function setOrderItem(?OrderItem $orderItem): static
    {
        $this->orderItem = $orderItem;

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

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }
}