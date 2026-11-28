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
    const TYPE_DELIVERY = 'delivery';
    const TYPE_RETURN_COLLECTION = 'return_collection';
    const TYPE_REDELIVERY_RETURN_ITEM = 'redelivery_return_item';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver', 'order:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'assignDelivery', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
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
    #[Groups(['assignDelivery:read', 'assignDelivery:write', 'order:read'])]
    private ?bool $isStarted = null;

    #[ORM\Column(length: 6, nullable: true)]
    #[Groups(['assignDelivery:read', 'assignDelivery:write', 'order:read'])]
    private ?string $deliveryPin = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['assignDelivery:read', 'assignDelivery:write', 'order:read'])]
    private ?\DateTime $estimatedArrivalTime = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['assignDelivery:read', 'assignDelivery:write', 'order:read', 'assignDelivery:driver'])]
    private ?string $type = null;

    #[ORM\ManyToOne(inversedBy: 'assignDeliveries')]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver'])]
    private ?ProductReturn $productReturn = null;

    #[ORM\ManyToOne(inversedBy: 'assignDeliveries')]
    #[Groups(['assignDelivery:read', 'assignDelivery:driver'])]
    private ?ProductReturnItem $productReturnItem = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->isCompleted = false;
        $this->isStarted = false;
        $this->estimatedArrivalTime = null;
        $this->type = self::TYPE_DELIVERY;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignOrder(): ?Order
    {
        return $this->assignOrder;
    }

    public function setAssignOrder(?Order $assignOrder): static
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

    public function getDeliveryPin(): ?string
    {
        return $this->deliveryPin;
    }

    public function setDeliveryPin(?string $deliveryPin): static
    {
        $this->deliveryPin = $deliveryPin;

        return $this;
    }

    public function getEstimatedArrivalTime(): ?\DateTime
    {
        return $this->estimatedArrivalTime;
    }

    public function setEstimatedArrivalTime(?\DateTime $estimatedArrivalTime): static
    {
        $this->estimatedArrivalTime = $estimatedArrivalTime;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
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

    public function getProductReturnItem(): ?ProductReturnItem
    {
        return $this->productReturnItem;
    }

    public function setProductReturnItem(?ProductReturnItem $productReturnItem): static
    {
        $this->productReturnItem = $productReturnItem;

        return $this;
    }
}