<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Repository\PaymentMethodRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Kyzegs\DoctrineEncryptionBundle\Attribute\Encrypted;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Post(
            denormalizationContext: ['groups' => ['card:write']],
            normalizationContext: ['groups' => ['card:read']],
            security: "is_granted('ROLE_USER')",
            processor: \App\State\PaymentMethodStateProcessor::class,
        ),
        new Get(
            normalizationContext: ['groups' => ['card:read']],
            security: "is_granted('ROLE_USER') and object.getUser() == user",
            cacheHeaders: ['cache_control' => 'no-store, no-cache, must-revalidate, max-age=0'],
            provider: \App\DataProvider\PaymentMethodDataProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/users/{customerId}/payment_methods',
            uriVariables: [
                'customerId' => new Link(
                    fromClass: User::class,
                    fromProperty: 'paymentMethods',
                ),
            ],
            paginationEnabled: true,
            paginationItemsPerPage: 10,
            order: ['createdAt' => 'desc'],
            normalizationContext: ['groups' => ['card:list']],
            security: "is_granted('ROLE_USER') and user.getId() == customerId",
            provider: \App\DataProvider\PaymentMethodDataProvider::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and object.getUser() == user",
            controller: \App\Controller\DeletePaymentController::class,
            cacheHeaders: ['cache_control' => 'no-store, no-cache, must-revalidate, max-age=0'],
        ),
        new Post(
            uriTemplate: '/payment_methods/{id}/edit',
            denormalizationContext: ['groups' => ['card:write']],
            normalizationContext: ['groups' => ['card:read']],
            security: "is_granted('ROLE_USER') and object.getUser() == user",
            controller: \App\Controller\EditPaymentMethodController::class,
            cacheHeaders: ['cache_control' => 'no-store, no-cache, must-revalidate, max-age=0'],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: PaymentMethodRepository::class)]
class PaymentMethod
{
    public const METHOD_CARD = 'card';
    public const METHOD_MOBILE_PAYMENT = 'mobile_payment';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['card:read', 'card:list'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?string $method = null;

    #[Assert\Regex(pattern: '/^\d{16}$/', message: 'Le numéro de carte doit contenir exactement 16 chiffres.')]
    #[Groups(['card:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Encrypted]
    private ?string $cardNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?string $expiry = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?string $cardHolderName = null;

    #[ORM\Column(length: 4, nullable: true)]
    #[Groups(['card:read', 'card:list'])]
    private ?string $lastFourDigits = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?string $cardBrand = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Encrypted]
    #[Groups(['card:write'])]
    private ?string $mobilePaymentNumber = null;

    #[ORM\ManyToOne(inversedBy: 'paymentMethods')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Encrypted]
    #[Groups(['card:write'])]
    private ?string $cvv = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?string $mobileProvider = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?bool $isDefault = null;

    #[ORM\Column]
    #[Groups(['card:read', 'card:list', 'card:write'])]
    private ?bool $isActive = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->isActive = true;
        $this->isDefault = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getCardNumber(): ?string
    {
        return $this->cardNumber;
    }

    public function setCardNumber(?string $cardNumber): static
    {
        $this->cardNumber = $cardNumber;
        if (self::METHOD_CARD === $this->method && $cardNumber) {
            $this->lastFourDigits = substr($cardNumber, -4);
            $this->cardBrand = $this->detectCardBrand($cardNumber);
        }

        return $this;
    }

    public function getExpiry(): ?string
    {
        return $this->expiry;
    }

    public function setExpiry(?string $expiry): static
    {
        $this->expiry = $expiry;

        return $this;
    }

    public function getCardHolderName(): ?string
    {
        return $this->cardHolderName;
    }

    public function setCardHolderName(?string $cardHolderName): static
    {
        $this->cardHolderName = $cardHolderName;

        return $this;
    }

    public function getLastFourDigits(): ?string
    {
        return $this->lastFourDigits;
    }

    public function setLastFourDigits(?string $lastFourDigits): static
    {
        $this->lastFourDigits = $lastFourDigits;

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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getMobilePaymentNumber(): ?string
    {
        return $this->mobilePaymentNumber;
    }

    public function setMobilePaymentNumber(?string $mobilePaymentNumber): static
    {
        $this->mobilePaymentNumber = $mobilePaymentNumber;
        if (self::METHOD_MOBILE_PAYMENT === $this->method && $mobilePaymentNumber) {
            $this->lastFourDigits = substr($mobilePaymentNumber, -4);
        }

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

    private function detectCardBrand(string $cardNumber): string
    {
        $patterns = [
            'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
            'mastercard' => '/^5[1-5][0-9]{14}$|^2(?:2[2-9][0-9]{2}|[3-6][0-9]{3}|7[0-1][0-9]{2}|720[0-9]{2})[0-9]{12}$/',
            'amex' => '/^3[47][0-9]{13}$/',
            'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
            'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
            'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        ];

        foreach ($patterns as $brand => $pattern) {
            if (preg_match($pattern, $cardNumber)) {
                return $brand;
            }
        }

        return 'unknown';
    }

    public function getCvv(): ?string
    {
        return $this->cvv;
    }

    public function setCvv(?string $cvv): static
    {
        $this->cvv = $cvv;

        return $this;
    }

    public function getMobileProvider(): ?string
    {
        return $this->mobileProvider;
    }

    public function setMobileProvider(?string $mobileProvider): static
    {
        $this->mobileProvider = $mobileProvider;

        return $this;
    }

    #[Groups(['card:read', 'card:list'])]
    public function isDefault(): ?bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(?bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    #[Groups(['card:read', 'card:list'])]
    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}