<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\OrderProcessedRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    normalizationContext: ['groups' => ['order_processed:read', 'order:read:admin']],
)]
#[ORM\Entity(repositoryClass: OrderProcessedRepository::class)]
class OrderProcessed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orderProcesseds')]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?Order $currentOrder = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $seller = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?float $totalUsdAmount = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $receipt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?\DateTime $shippedFromUsaAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?float $shippingCost = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $sellerOrderReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $shippingReceipt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?\DateTime $collectedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $processingNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $shippingNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?string $collectionNote = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['order_processed:read', 'order:read:admin'])]
    private ?\DateTime $processedAt = null;

    #[ORM\ManyToOne(inversedBy: 'orderProcesseds')]
    #[Groups(['order_processed:read', 'order:read:admin',])]
    private ?Courrier $courrier = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $outForDeliveryAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrentOrder(): ?Order
    {
        return $this->currentOrder;
    }

    public function setCurrentOrder(?Order $currentOrder): static
    {
        $this->currentOrder = $currentOrder;

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

    public function getSeller(): ?string
    {
        return $this->seller;
    }

    public function setSeller(string $seller): static
    {
        $this->seller = $seller;

        return $this;
    }

    public function getTotalUsdAmount(): ?float
    {
        return $this->totalUsdAmount;
    }

    public function setTotalUsdAmount(float $totalUsdAmount): static
    {
        $this->totalUsdAmount = $totalUsdAmount;

        return $this;
    }

    public function getReceipt(): ?string
    {
        return $this->receipt;
    }

    public function setReceipt(string $receipt): static
    {
        $this->receipt = $receipt;

        return $this;
    }

    public function getShippedFromUsaAt(): ?\DateTime
    {
        return $this->shippedFromUsaAt;
    }

    public function setShippedFromUsaAt(?\DateTime $shippedFromUsaAt): static
    {
        $this->shippedFromUsaAt = $shippedFromUsaAt;

        return $this;
    }

    public function getShippingCost(): ?float
    {
        return $this->shippingCost;
    }

    public function setShippingCost(?float $shippingCost): static
    {
        $this->shippingCost = $shippingCost;

        return $this;
    }

    public function getSellerOrderReference(): ?string
    {
        return $this->sellerOrderReference;
    }

    public function setSellerOrderReference(string $sellerOrderReference): static
    {
        $this->sellerOrderReference = $sellerOrderReference;

        return $this;
    }

    public function getShippingReceipt(): ?string
    {
        return $this->shippingReceipt;
    }

    public function setShippingReceipt(?string $shippingReceipt): static
    {
        $this->shippingReceipt = $shippingReceipt;

        return $this;
    }

    public function getCollectedAt(): ?\DateTime
    {
        return $this->collectedAt;
    }

    public function setCollectedAt(?\DateTime $collectedAt): static
    {
        $this->collectedAt = $collectedAt;

        return $this;
    }

    public function getProcessingNote(): ?string
    {
        return $this->processingNote;
    }

    public function setProcessingNote(?string $processingNote): static
    {
        $this->processingNote = $processingNote;

        return $this;
    }

    public function getShippingNote(): ?string
    {
        return $this->shippingNote;
    }

    public function setShippingNote(?string $shippingNote): static
    {
        $this->shippingNote = $shippingNote;

        return $this;
    }

    public function getCollectionNote(): ?string
    {
        return $this->collectionNote;
    }

    public function setCollectionNote(?string $collectionNote): static
    {
        $this->collectionNote = $collectionNote;

        return $this;
    }

    public function getProcessedAt(): ?\DateTime
    {
        return $this->processedAt;
    }

    public function setProcessedAt(\DateTime $processedAt): static
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    public function getCourrier(): ?Courrier
    {
        return $this->courrier;
    }

    public function setCourrier(?Courrier $courrier): static
    {
        $this->courrier = $courrier;

        return $this;
    }

    public function getOutForDeliveryAt(): ?\DateTime
    {
        return $this->outForDeliveryAt;
    }

    public function setOutForDeliveryAt(?\DateTime $outForDeliveryAt): static
    {
        $this->outForDeliveryAt = $outForDeliveryAt;

        return $this;
    }
}