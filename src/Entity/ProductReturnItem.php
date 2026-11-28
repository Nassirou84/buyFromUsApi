<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductReturnItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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

    const CONDITION_GRADE_A = 'grade_a';
    const CONDITION_GRADE_B = 'grade_b';
    const CONDITION_GRADE_C = 'grade_c';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product_return:read', 'assignDelivery:driver', 'assignDelivery:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'returnItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?ProductReturn $productReturn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['product_return:read', 'assignDelivery:driver', 'assignDelivery:read'])]
    #[Assert\NotNull]
    private ?OrderItem $orderItem = null;

    #[ORM\Column]
    #[Groups(['product_return:read', 'assignDelivery:driver', 'assignDelivery:read'])]
    #[Assert\Positive]
    private ?int $quantity = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product_return:read', 'assignDelivery:driver', 'assignDelivery:read'])]
    #[Assert\NotBlank]
    private ?string $reason = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['product_return:read', 'assignDelivery:read', 'assignDelivery:driver'])]
    private ?string $itemCondition = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    #[Groups(['product_return:read'])]
    private ?array $itemControls = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_return:read'])]
    private ?\DateTime $inspectedAt = null;

    #[ORM\ManyToOne]
    #[Groups(['product_return:read'])]
    private ?User $inspectedBy = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['product_return:read', 'assignDelivery:read', 'assignDelivery:driver'])]
    private ?string $decision = null;

    #[ORM\ManyToOne]
    #[Groups(['product_return:read'])]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_return:read'])]
    private ?\DateTime $decidedAt = null;

    /**
     * @var Collection<int, AssignDelivery>
     */
    #[ORM\OneToMany(targetEntity: AssignDelivery::class, mappedBy: 'productReturnItem')]
    private Collection $assignDeliveries;

    public function __construct()
    {
        $this->assignDeliveries = new ArrayCollection();
    }

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

    public function getItemCondition(): ?string
    {
        return $this->itemCondition;
    }

    public function setItemCondition(?string $itemCondition): static
    {
        $this->itemCondition = $itemCondition;

        return $this;
    }

    public function getItemControls(): ?array
    {
        return $this->itemControls;
    }

    public function setItemControls(?array $itemControls): static
    {
        $this->itemControls = $itemControls;

        return $this;
    }

    public function getInspectedAt(): ?\DateTime
    {
        return $this->inspectedAt;
    }

    public function setInspectedAt(\DateTime $inspectedAt): static
    {
        $this->inspectedAt = $inspectedAt;

        return $this;
    }

    public function getInspectedBy(): ?User
    {
        return $this->inspectedBy;
    }

    public function setInspectedBy(?User $inspectedBy): static
    {
        $this->inspectedBy = $inspectedBy;

        return $this;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function setDecision(?string $decision): static
    {
        $this->decision = $decision;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getDecidedAt(): ?\DateTime
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTime $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    /**
     * @return Collection<int, AssignDelivery>
     */
    public function getAssignDeliveries(): Collection
    {
        return $this->assignDeliveries;
    }

    public function addAssignDelivery(AssignDelivery $assignDelivery): static
    {
        if (!$this->assignDeliveries->contains($assignDelivery)) {
            $this->assignDeliveries->add($assignDelivery);
            $assignDelivery->setProductReturnItem($this);
        }

        return $this;
    }

    public function removeAssignDelivery(AssignDelivery $assignDelivery): static
    {
        if ($this->assignDeliveries->removeElement($assignDelivery)) {
            // set the owning side to null (unless already changed)
            if ($assignDelivery->getProductReturnItem() === $this) {
                $assignDelivery->setProductReturnItem(null);
            }
        }

        return $this;
    }

    /**
     * The driver run bringing this (rejected) item back to the customer, if any.
     */
    public function findRedelivery(): ?AssignDelivery
    {
        foreach ($this->assignDeliveries as $assignment) {
            if ($assignment->getType() === AssignDelivery::TYPE_REDELIVERY_RETURN_ITEM) {
                return $assignment;
            }
        }

        return null;
    }

    /**
     * Send-back state shown on the return (null until the item is sent back).
     *
     * @return array{driverName: string, isStarted: bool, isCompleted: bool, createdAt: ?\DateTime, completedAt: ?\DateTime}|null
     */
    #[Groups(['product_return:read'])]
    public function getRedelivery(): ?array
    {
        $assignment = $this->findRedelivery();
        if (!$assignment) {
            return null;
        }

        return [
            'driverName' => $assignment->getDriver()?->getFullName() ?? '',
            'isStarted' => (bool) $assignment->isStarted(),
            'isCompleted' => (bool) $assignment->isCompleted(),
            'createdAt' => $assignment->getCreatedAt(),
            'completedAt' => $assignment->getCompletedAt(),
        ];
    }
}