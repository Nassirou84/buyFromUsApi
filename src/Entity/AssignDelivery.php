<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Entity\User;
use App\Repository\AssignDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_DRIVER')",
            uriTemplate: '/driver/{driver}/assign-deliveries',
            uriVariables: [
                'driver' => new Link(
                    fromClass: User::class,
                    fromProperty: 'assignDeliveries',
                ),
            ],
            // Dedicated, narrow group: the driver list only needs the order
            // number, recipient name and address. Using the full `order:read`
            // group here eager-loads the order's products/payments relations
            // and blows past API Platform's max_joins limit.
            normalizationContext: ['groups' => ['assignDelivery:driver']],
        ),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    filters: ['assign_deliveries.search_filter', 'assign_deliveries.order_filter'],
    normalizationContext: ['groups' => ['assignDelivery:read', 'order:read']],
    denormalizationContext: ['groups' => ['assignDelivery:write']]
)]
#[ORM\Entity(repositoryClass: AssignDeliveryRepository::class)]
class AssignDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'order:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'assignDelivery', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write'])]
    private ?Order $assignOrder = null;

    #[ORM\Column]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write', 'order:read'])]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write', 'order:read'])]
    private ?\DateTime $completedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write', 'order:read'])]
    private ?string $signedName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write', 'order:read'])]
    private ?\DateTime $startedAt = null;

    #[ORM\ManyToOne(inversedBy: 'assignDeliveries')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['assignDelivery:read', 'assignDelivery:write', 'order:read'])]
    private ?User $driver = null;

    #[ORM\Column]
    #[Groups(['assignDelivery:read', 'assignDelivery:write', 'order:read'])]
    private ?bool $isCompleted = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isStarted = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->isCompleted = false;
        $this->isStarted = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignOrder(): ?Order
    {
        return $this->assignOrder;
    }

    public function setAssignOrder(Order $assignOrder): static
    {
        $this->assignOrder = $assignOrder;

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

    public function getCompletedAt(): ?\DateTime
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTime $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getSignedName(): ?string
    {
        return $this->signedName;
    }

    public function setSignedName(?string $signedName): static
    {
        $this->signedName = $signedName;

        return $this;
    }

    public function getStartedAt(): ?\DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTime $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getDriver(): ?User
    {
        return $this->driver;
    }

    public function setDriver(?User $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write', 'order:read'])]
    #[SerializedName('isCompleted')]
    public function isCompleted(): ?bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }

    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'assignDelivery:write', 'order:read'])]
    #[SerializedName('isStarted')]
    public function isStarted(): ?bool
    {
        return $this->isStarted;
    }

    public function setIsStarted(?bool $isStarted): static
    {
        $this->isStarted = $isStarted;

        return $this;
    }
}