<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Controller\CurrentlyLoginController;
use App\Controller\EditCurrentUserController;
use App\Repository\UserRepository;
use App\State\RegisterStateProcessor;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;

#[GetCollection(
    normalizationContext: ['groups' => ['user:read']],
    security: "is_granted('ROLE_ADMIN')",
)]
#[ApiResource(
    operations: [
        new Post(
            processor: RegisterStateProcessor::class,
            denormalizationContext: ['groups' => ['user:create']],
        ),
        new GetCollection(),
        new Get(),
        new GetCollection(
            controller: CurrentlyLoginController::class,
            security: 'is_granted("ROLE_USER")',
            uriTemplate: '/authenticated',
        ),
        new GetCollection(
            routeName: 'app_change_password_validate',
            openapi: new Operation(
                summary: 'Validate password reset token',
                description: 'Validates the password reset token and returns a response indicating whether the token is valid or not.',
            ),
        ),
        new Post(
            security: 'is_granted("ROLE_USER")',
            controller: EditCurrentUserController::class,
            uriTemplate: '/edit-me',
            denormalizationContext: ['groups' => ['user:edit']],
        ),
        new Post(
            routeName: 'app_change_password_request',
            deserialize: false,
        ),
        new Post(
            routeName: 'app_change_password',
            deserialize: false,
        ),
    ],
    denormalizationContext: ['groups' => ['user:create', 'user:edit']],
    normalizationContext: ['groups' => ['user:read', 'user:login:read']],
)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    // @phpstan-ignore property.onlyRead
    private $id;

    #[ORM\Column(length: 180)]
    #[Groups(['user:create', 'user:login:read'])]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    #[Groups(['user:create', 'user:login:read'])]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['user:create'])]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $registrationToken = null;

    #[ORM\Column(nullable: true)]
    private ?DateTime $registrationTokenCreatedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['user:create', 'user:read'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    #[Groups(['user:create', 'user:read'])]
    private ?string $lastName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:read'])]
    private ?DateTimeImmutable $createdAt = null;

    #[Groups(['user:read', 'user:edit'])]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phone = null;

    #[Groups(['user:read', 'user:edit'])]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $what3words = null;

    #[Groups(['user:read', 'user:edit'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $street = null;

    #[Groups(['user:read', 'user:edit'])]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[Groups(['user:read', 'user:edit'])]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:edit'])]
    private ?string $country = null;

    #[Groups(['user:login:read'])]
    #[ORM\Column(nullable: true)]
    private ?DateTime $lastLoginAt = null;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'customer')]
    private Collection $orders;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    /**
     * @var Collection<int, Wishlist>
     */
    #[ORM\OneToMany(targetEntity: Wishlist::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $wishlists;

    /**
     * @var Collection<int, ShoppingRequest>
     */
    #[ORM\OneToMany(targetEntity: ShoppingRequest::class, mappedBy: 'customer')]
    private Collection $shoppingRequests;

    /**
     * @var Collection<int, PaymentMethod>
     */
    #[ORM\OneToMany(targetEntity: PaymentMethod::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $paymentMethods;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Basket $basket = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?DateTime $passwordResetTokenExpiredAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:login:read', 'user:edit'])]
    private ?array $addresses = [];

    #[ORM\Column(nullable: true)]
    #[Groups(['user:login:read', 'user:edit'])]
    private ?bool $twoFactor = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $twoFactorCode = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['user:login:read', 'user:edit'])]
    private ?string $twoFactorContactMethod = null;

    /**
     * @var Collection<int, TrustedDevice>
     */
    #[ORM\OneToMany(targetEntity: TrustedDevice::class, mappedBy: 'user')]
    private Collection $trustedDevices;

    public function __construct()
    {
        $this->country = 'CIV';
        $this->orders = new ArrayCollection();
        $this->wishlists = new ArrayCollection();
        $this->shoppingRequests = new ArrayCollection();
        $this->paymentMethods = new ArrayCollection();
        $this->twoFactor = false;
        $this->createdAt = new DateTimeImmutable();
        $this->twoFactorContactMethod = 'email';
        $this->trustedDevices = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    public function getRegistrationToken(): ?string
    {
        return $this->registrationToken;
    }

    public function setRegistrationToken(?string $registrationToken): static
    {
        $this->registrationToken = $registrationToken;

        return $this;
    }

    public function getRegistrationTokenCreatedAt(): ?DateTime
    {
        return $this->registrationTokenCreatedAt;
    }

    public function setRegistrationTokenCreatedAt(?DateTime $registrationTokenCreatedAt): static
    {
        $this->registrationTokenCreatedAt = $registrationTokenCreatedAt;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getWhat3words(): ?string
    {
        return $this->what3words;
    }

    public function setWhat3words(?string $what3words): static
    {
        $this->what3words = $what3words;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getLastLoginAt(): ?DateTime
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?DateTime $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    /**
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setCustomer($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): static
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getCustomer() === $this) {
                $order->setCustomer(null);
            }
        }

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    /**
     * @return Collection<int, Wishlist>
     */
    public function getWishlists(): Collection
    {
        return $this->wishlists;
    }

    public function addWishlist(Wishlist $wishlist): static
    {
        if (!$this->wishlists->contains($wishlist)) {
            $this->wishlists->add($wishlist);
            $wishlist->setUser($this);
        }

        return $this;
    }

    public function removeWishlist(Wishlist $wishlist): static
    {
        if ($this->wishlists->removeElement($wishlist)) {
            // set the owning side to null (unless already changed)
            if ($wishlist->getUser() === $this) {
                $wishlist->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ShoppingRequest>
     */
    public function getShoppingRequests(): Collection
    {
        return $this->shoppingRequests;
    }

    public function addShoppingRequest(ShoppingRequest $shoppingRequest): static
    {
        if (!$this->shoppingRequests->contains($shoppingRequest)) {
            $this->shoppingRequests->add($shoppingRequest);
            $shoppingRequest->setCustomer($this);
        }

        return $this;
    }

    public function removeShoppingRequest(ShoppingRequest $shoppingRequest): static
    {
        if ($this->shoppingRequests->removeElement($shoppingRequest)) {
            // set the owning side to null (unless already changed)
            if ($shoppingRequest->getCustomer() === $this) {
                $shoppingRequest->setCustomer(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function getPaymentMethods(): Collection
    {
        return $this->paymentMethods;
    }

    public function addPaymentMethod(PaymentMethod $paymentMethod): static
    {
        if (!$this->paymentMethods->contains($paymentMethod)) {
            $this->paymentMethods->add($paymentMethod);
            $paymentMethod->setUser($this);
        }

        return $this;
    }

    public function removePaymentMethod(PaymentMethod $paymentMethod): static
    {
        if ($this->paymentMethods->removeElement($paymentMethod)) {
            // set the owning side to null (unless already changed)
            if ($paymentMethod->getUser() === $this) {
                $paymentMethod->setUser(null);
            }
        }

        return $this;
    }

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(Basket $basket): static
    {
        // set the owning side of the relation if necessary
        if ($basket->getUser() !== $this) {
            $basket->setUser($this);
        }

        $this->basket = $basket;

        return $this;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;

        return $this;
    }

    public function getPasswordResetTokenExpiredAt(): ?DateTime
    {
        return $this->passwordResetTokenExpiredAt;
    }

    public function setPasswordResetTokenExpiredAt(?DateTime $passwordResetTokenExpiredAt): static
    {
        $this->passwordResetTokenExpiredAt = $passwordResetTokenExpiredAt;

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function getAddresses(): ?array
    {
        return $this->addresses;
    }

    public function setAddresses(?array $addresses): static
    {
        $this->addresses = $addresses;

        return $this;
    }

    #[Groups(['user:login:read', 'user:edit'])]
    public function isTwoFactor(): ?bool
    {
        return $this->twoFactor;
    }

    public function setTwoFactor(?bool $twoFactor): static
    {
        $this->twoFactor = $twoFactor;

        return $this;
    }

    public function getTwoFactorCode(): ?string
    {
        return $this->twoFactorCode;
    }

    public function setTwoFactorCode(?string $twoFactorCode): static
    {
        $this->twoFactorCode = $twoFactorCode;

        return $this;
    }

    public function getTwoFactorContactMethod(): ?string
    {
        return $this->twoFactorContactMethod;
    }

    public function setTwoFactorContactMethod(?string $twoFactorContactMethod): static
    {
        $this->twoFactorContactMethod = $twoFactorContactMethod;

        return $this;
    }

    /**
     * @return Collection<int, TrustedDevice>
     */
    public function getTrustedDevices(): Collection
    {
        return $this->trustedDevices;
    }

    public function addTrustedDevice(TrustedDevice $trustedDevice): static
    {
        if (!$this->trustedDevices->contains($trustedDevice)) {
            $this->trustedDevices->add($trustedDevice);
            $trustedDevice->setUser($this);
        }

        return $this;
    }

    public function removeTrustedDevice(TrustedDevice $trustedDevice): static
    {
        if ($this->trustedDevices->removeElement($trustedDevice)) {
            // set the owning side to null (unless already changed)
            if ($trustedDevice->getUser() === $this) {
                $trustedDevice->setUser(null);
            }
        }

        return $this;
    }
}
