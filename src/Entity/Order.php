<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Controller\CancelOrderController;
use App\Controller\OrderSummaryController;
use App\Controller\PlaceOrderController;
use App\Repository\OrderRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(
            processor: \App\Service\OrderService::class,
        ),
        new GetCollection(
            uriTemplate: '/order/summary',
            controller: OrderSummaryController::class,
        ),
        new GetCollection(
            uriTemplate: '/customer/{customerId}/orders',
            uriVariables: [
                'customerId' => new Link(
                    fromClass: User::class,
                    fromProperty: 'orders',
                ),
            ],
            paginationItemsPerPage: 10,
            order: ['createdAt' => 'desc'],
        ),
        new Post(
            controller: PlaceOrderController::class,
            uriTemplate: '/orders/finalize',
        ),
        new Post(
            controller: CancelOrderController::class,
            uriTemplate: '/orders/{id}/cancel',
        ),
        new Put(
            denormalizationContext: ['groups' => ['order:edit']],
        ),
        new Delete(),
    ],
    denormalizationContext: ['groups' => ['order:write', 'order:edit']],
    normalizationContext: ['groups' => ['order:read']],
    filters: ['my_order.search_filter', 'my_order.order_filter'],
)]
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
class Order
{
    public const STATUS_ORDER_PLACED = 'placed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_ARRIVED_IN_FACILITY = 'arrived_in_facility';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['order:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['order:write', 'order:read'])]
    private ?float $orderPrice = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?User $customer = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read'])]
    private ?string $fullName = null;


    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read'])]
    private ?string $street = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read'])]
    private ?string $city = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read'])]
    private ?string $state = null;

    #[ORM\Column]
    #[Groups(['order:write', 'order:read'])]
    private ?DateTime $createdAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:read', 'order:edit'])]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'order:edit'])]
    private ?DateTime $updatedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:read'])]
    private ?string $uid = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'currentOrder', orphanRemoval: true)]
    #[Groups(['order:read'])]
    private Collection $products;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read'])]
    private ?DateTime $estimatedDeliveryAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $suite = null;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'userOrder')]
    private Collection $payments;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isPriority = null;

    #[ORM\Column(nullable: true)]
    private ?float $discount = null;

    #[ORM\Column(nullable: true)]
    private ?float $taxes = null;

    #[ORM\Column(nullable: true)]
    private ?float $shippingFee = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255)]
    private ?string $accessToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $deliveredAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->status = self::STATUS_ORDER_PLACED;
        $this->products = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderPrice(): ?float
    {
        return $this->orderPrice;
    }

    public function setOrderPrice(float $orderPrice): static
    {
        $this->orderPrice = $orderPrice;

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

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

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

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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

    /**
     * @return Collection<int, OrderItem>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(OrderItem $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setCurrentOrder($this);
        }

        return $this;
    }

    public function removeProduct(OrderItem $product): static
    {
        if ($this->products->removeElement($product)) {
            // set the owning side to null (unless already changed)
            if ($product->getCurrentOrder() === $this) {
                $product->setCurrentOrder(null);
            }
        }

        return $this;
    }

    public function getEstimatedDeliveryAt(): ?DateTime
    {
        return $this->estimatedDeliveryAt;
    }

    public function setEstimatedDeliveryAt(?DateTime $estimatedDeliveryAt): static
    {
        $this->estimatedDeliveryAt = $estimatedDeliveryAt;

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

    public function getSuite(): ?string
    {
        return $this->suite;
    }

    public function setSuite(?string $suite): static
    {
        $this->suite = $suite;

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setUserOrder($this);
        }

        return $this;
    }

    public function removePayment(Payment $payment): static
    {
        if ($this->payments->removeElement($payment)) {
            // set the owning side to null (unless already changed)
            if ($payment->getUserOrder() === $this) {
                $payment->setUserOrder(null);
            }
        }

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function isPriority(): ?bool
    {
        return $this->isPriority;
    }

    public function setIsPriority(?bool $isPriority): static
    {
        $this->isPriority = $isPriority;

        return $this;
    }

    public function getFullAddress(): ?string
    {
        return $this->street
            . ($this->suite ? ', ' . $this->suite : '')
            . ($this->city ? ', ' . $this->city : '')
            . ($this->state ? ', ' . $this->state : '')
            . ($this->country ? ', ' . $this->country : '');
    }

    public function getDiscount(): ?float
    {
        return $this->discount;
    }

    public function setDiscount(?float $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getTaxes(): ?float
    {
        return $this->taxes;
    }

    public function setTaxes(?float $taxes): static
    {
        $this->taxes = $taxes;

        return $this;
    }

    public function getShippingFee(): ?float
    {
        return $this->shippingFee;
    }

    public function setShippingFee(?float $shippingFee): static
    {
        $this->shippingFee = $shippingFee;

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

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getDeliveredAt(): ?\DateTime
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTime $deliveredAt): static
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }
}