<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\ShoppingRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Post(
            uriTemplate: '/shopping_requests/create',
            controller: \App\Controller\CreateShoppingRequestController::class,
            deserialize: false,
        ),
    ],
    normalizationContext: ['groups' => ['shopping_request:read']],
)]
#[ORM\Entity(repositoryClass: ShoppingRequestRepository::class)]
class ShoppingRequest
{

    const STATUS_SUBMITTED = 'submitted';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_ORDER_PLACED = 'order_placed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups('shopping_request:read')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?int $quantity = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $fullName = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $address = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read', 'shopping_request:write'])]
    private ?string $preferredContact = null;

    #[ORM\Column]
    #[Groups(['shopping_request:read'])]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updatedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read'])]
    private ?string $status = null;

    #[ORM\Column]
    #[Groups(['shopping_request:read'])]
    private array $images = [];

    #[ORM\Column(length: 255)]
    #[Groups(['shopping_request:read'])]
    private ?string $uid = null;

    #[ORM\ManyToOne(inversedBy: 'shoppingRequests')]
    #[Groups(['shopping_request:read'])]
    private ?User $customer = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->status = 'submitted';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPreferredContact(): ?string
    {
        return $this->preferredContact;
    }

    public function setPreferredContact(string $preferredContact): static
    {
        $this->preferredContact = $preferredContact;

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

    public function setUpdatedAt(?\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    public function setImages(array $images): static
    {
        $this->images = $images;

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

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): static
    {
        $this->customer = $customer;

        return $this;
    }
}