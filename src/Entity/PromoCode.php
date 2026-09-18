<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new GetCollection(
            routeName: 'api_check_promo_code'
        )
    ]
)]
#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
class PromoCode
{
    const APPLY_ALL_USERS = 'all_users';
    const APPLY_SPECIFIC_USERS = 'specific_users';
    const APPLY_FIRST_TIME_PURCHASE = 'first_time_purchase';
    const APPLY_MINIMUM_ORDER_AMOUNT = 'target_amount';
    const APPLY_TARGET_QUANTITY = 'target_quantity';
    const APPLY_TARGET_CATEGORY = 'target_category';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 15)]
    private ?string $code = null;

    #[ORM\ManyToOne(inversedBy: 'promoCodes')]
    private ?User $user = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column]
    private ?\DateTime $expiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $terms = null;

    #[ORM\Column(length: 255)]
    private ?string $discount = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $target = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function getExpiresAt(): ?\DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTime $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getTerms(): ?string
    {
        return $this->terms;
    }

    public function setTerms(?string $terms): static
    {
        $this->terms = $terms;

        return $this;
    }

    public function getDiscount(): ?string
    {
        return $this->discount;
    }

    public function setDiscount(string $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function setTarget(?string $target): static
    {
        $this->target = $target;

        return $this;
    }
}