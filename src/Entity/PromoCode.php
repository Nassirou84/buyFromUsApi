<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\PromoCodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ApiResource(
    operations: [
        new GetCollection(
            routeName: 'api_check_promo_code'
        ),
        new GetCollection(
            routeName: 'app_get_available_discount',
            paginationEnabled: false
        ),
        new GetCollection(
            uriTemplate: '/promo_codes',
            name: 'admin_list_promo_codes',
            order: ['createdAt' => 'DESC'],
            paginationEnabled: false,
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')"
        )
    ],
    denormalizationContext: ['groups' => ['promo_code:write']],
    normalizationContext: ['groups' => ['promo_code:read']]
)]
#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PROMO_CODE_CODE', fields: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'promo_code_already_exists')]
class PromoCode
{
    const APPLY_ALL_USERS = 'all_users';
    const APPLY_SPECIFIC_USERS = 'specific_users';
    const APPLY_FIRST_TIME_PURCHASE = 'first_time_purchase';
    const APPLY_MINIMUM_ORDER_AMOUNT = 'target_amount';
    const APPLY_TARGET_QUANTITY = 'target_quantity';
    const APPLY_TARGET_CATEGORY = 'target_category';

    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_FIXED = 'fixed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['promo_code:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    #[Assert\NotBlank]
    private ?string $code = null;

    #[ORM\ManyToOne(inversedBy: 'promoCodes')]
    private ?User $user = null;

    #[ORM\Column]
    #[Groups(['promo_code:read'])]
    private ?\DateTime $createdAt = null;

    // Null means the code never expires.
    #[ORM\Column(nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    private ?\DateTime $expiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    private ?string $terms = null;

    // A percentage (0-100) or a fixed amount, depending on $type.
    #[ORM\Column(length: 255)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(\.\d+)?$/', message: 'promo_code_discount_invalid')]
    private ?string $discount = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    private ?string $target = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    private ?string $campaignLabel = null;

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_PERCENTAGE])]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    #[Assert\Choice(choices: [self::TYPE_PERCENTAGE, self::TYPE_FIXED])]
    private string $type = self::TYPE_PERCENTAGE;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    // Null means the code is valid from the moment it is created.
    #[ORM\Column(nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    private ?\DateTime $startsAt = null;

    // Null means unlimited redemptions.
    #[ORM\Column(nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    #[Assert\Positive]
    private ?int $maxClaims = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['promo_code:read', 'promo_code:write'])]
    #[Assert\PositiveOrZero]
    private ?float $minSpend = null;

    /**
     * @var Collection<int, PromoCodeUsage>
     */
    #[ORM\OneToMany(targetEntity: PromoCodeUsage::class, mappedBy: 'promoCode', cascade: ['remove'], fetch: 'EXTRA_LAZY')]
    private Collection $usages;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->usages = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateDiscountForType(ExecutionContextInterface $context): void
    {
        if (self::TYPE_PERCENTAGE === $this->type && (float) $this->discount > 100) {
            $context->buildViolation('promo_code_percentage_over_100')->atPath('discount')->addViolation();
        }
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

    public function setExpiresAt(?\DateTime $expiresAt): static
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

    public function getCampaignLabel(): ?string
    {
        return $this->campaignLabel;
    }

    public function setCampaignLabel(?string $campaignLabel): static
    {
        $this->campaignLabel = $campaignLabel;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    #[Groups(['promo_code:read', 'promo_code:write'])]
    #[SerializedName('isActive')]
    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getStartsAt(): ?\DateTime
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTime $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getMaxClaims(): ?int
    {
        return $this->maxClaims;
    }

    public function setMaxClaims(?int $maxClaims): static
    {
        $this->maxClaims = $maxClaims;

        return $this;
    }

    public function getMinSpend(): ?float
    {
        return $this->minSpend;
    }

    public function setMinSpend(?float $minSpend): static
    {
        $this->minSpend = $minSpend;

        return $this;
    }

    /**
     * @return Collection<int, PromoCodeUsage>
     */
    #[Ignore]
    public function getUsages(): Collection
    {
        return $this->usages;
    }

    /**
     * Number of times the code has been redeemed (one per user).
     */
    #[Groups(['promo_code:read'])]
    public function getCurrentClaims(): int
    {
        return $this->usages->count();
    }
}
