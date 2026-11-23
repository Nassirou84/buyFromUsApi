<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\DataProvider\PaymentMethodDataProvider;
use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/payment-methods',
            security: "is_granted('ROLE_USER')",
            provider: PaymentMethodDataProvider::class,
            normalizationContext: ['groups' => ['payment_method:read']],
        ),
        new Get(
            uriTemplate: '/payment-methods/{id}',
            security: "is_granted('ROLE_USER')",
            provider: PaymentMethodDataProvider::class,
            normalizationContext: ['groups' => ['payment_method:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: PaymentMethodRepository::class)]
#[ORM\Index(name: 'idx_pm_auth_fingerprint', columns: ['authorization_fingerprint'])]
#[ORM\Index(name: 'idx_pm_card_signature', columns: ['card_signature'])]
class PaymentMethod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['payment_method:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    // Sensitive: the Paystack authorization (charge) token — never exposed via the API.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authorizationCode = null;

    // Blind index for the authorization token: HMAC(token, pepper). KMS encryption
    // is non-deterministic, so the ciphertext cannot be matched in a query; this
    // deterministic, non-reversible fingerprint is what dedupe looks up. Never
    // exposed via the API.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authorizationFingerprint = null;

    // Paystack's per-card identifier (`SIG_...`) from the authorization object.
    // THIS is what identifies a card across transactions: the authorization_code
    // is per-authorization and Paystack hands out a NEW one for each transaction,
    // even for the same card and customer, so it cannot be used to dedupe.
    // Not a chargeable credential (unlike the authorization token) and scoped to
    // Paystack, so it is stored as-is to stay queryable; never exposed via the API.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cardSignature = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['payment_method:read'])]
    private ?string $cardLast4 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['payment_method:read'])]
    private ?string $cardBrand = null;

    #[ORM\Column(length: 255)]
    #[Groups(['payment_method:read'])]
    private ?string $methodType = null;

    #[ORM\Column]
    #[Groups(['payment_method:read'])]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isDefault = null;

    #[ORM\ManyToOne(inversedBy: 'paymentMethods')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $customer = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['payment_method:read'])]
    private ?string $expiryMonth = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['payment_method:read'])]
    private ?string $expiryYear = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthorizationCode(): ?string
    {
        return $this->authorizationCode;
    }

    public function setAuthorizationCode(?string $authorizationCode): static
    {
        $this->authorizationCode = $authorizationCode;

        return $this;
    }

    public function getAuthorizationFingerprint(): ?string
    {
        return $this->authorizationFingerprint;
    }

    public function setAuthorizationFingerprint(?string $authorizationFingerprint): static
    {
        $this->authorizationFingerprint = $authorizationFingerprint;

        return $this;
    }

    public function getCardSignature(): ?string
    {
        return $this->cardSignature;
    }

    public function setCardSignature(?string $cardSignature): static
    {
        $this->cardSignature = $cardSignature;

        return $this;
    }

    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    public function setCardLast4(?string $cardLast4): static
    {
        $this->cardLast4 = $cardLast4;

        return $this;
    }

    public function getCardBrand(): ?string
    {
        return $this->cardBrand;
    }

    public function setCardBrand(?string $cardBrand): static
    {
        $this->cardBrand = $cardBrand;

        return $this;
    }

    public function getMethodType(): ?string
    {
        return $this->methodType;
    }

    public function setMethodType(string $methodType): static
    {
        $this->methodType = $methodType;

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

    #[Groups(['payment_method:read'])]
    #[SerializedName('isDefault')]
    public function isDefault(): ?bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(?bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getExpiryMonth(): ?string
    {
        return $this->expiryMonth;
    }

    public function setExpiryMonth(?string $expiryMonth): static
    {
        $this->expiryMonth = $expiryMonth;

        return $this;
    }

    public function getExpiryYear(): ?string
    {
        return $this->expiryYear;
    }

    public function setExpiryYear(?string $expiryYear): static
    {
        $this->expiryYear = $expiryYear;

        return $this;
    }
}