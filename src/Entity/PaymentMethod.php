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