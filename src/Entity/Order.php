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
use App\Enumeration\OrderStatusEnum;
use App\Repository\OrderRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_ADMIN') or object.getCustomer() == user",
            normalizationContext: ['groups' => ['order:read', 'order:read:details']],
        ),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['order:read']],
            paginationItemsPerPage: 10,
            filters: ['my_order.search_filter', 'my_order.order_filter'],
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: \App\Service\OrderService::class,
        ),
        new GetCollection(
            uriTemplate: '/order/summary',
            controller: OrderSummaryController::class,
            normalizationContext: ['groups' => []],
        ),
        new GetCollection(
            uriTemplate: '/customer/{customerId}/orders',
            uriVariables: [
                'customerId' => new Link(
                    fromClass: User::class,
                    fromProperty: 'orders',
                ),
            ],
            filters: ['my_order.search_filter', 'my_order.order_filter'],
            paginationItemsPerPage: 10,
            order: ['createdAt' => 'desc'],
            security: "is_granted('ROLE_ADMIN') or user.getId() == customerId",
            normalizationContext: ['groups' => ['order:read', 'order:read:details']],
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            controller: CancelOrderController::class,
            uriTemplate: '/orders/{id}/cancel',
        ),
        new Put(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['order:edit']],
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    denormalizationContext: ['groups' => ['order:write', 'order:edit', 'order:read:admin']],
    normalizationContext: ['groups' => ['order:read', 'order:read:details', 'assignDelivery:read', 'product_return:read']],
    filters: ['my_order.search_filter', 'my_order.order_filter'],
)]
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORDER_UID', fields: ['uid'])]
class Order
{
    /**
     * The ordered tracking flow a (non-cancelled) order moves through. Drives
     * the tracking history timeline exposed to the client.
     *
     * @var list<string>
     */
    public const TRACKING_FLOW = [
        OrderStatusEnum::PENDING->value,
        OrderStatusEnum::PLACED->value,
        OrderStatusEnum::PROCESSED->value,
        OrderStatusEnum::IN_TRANSIT->value,
        OrderStatusEnum::ARRIVED_IN_FACILITY->value,
        OrderStatusEnum::OUT_FOR_DELIVERY->value,
        OrderStatusEnum::DELIVERED->value,
        OrderStatusEnum::CANCELLED->value,
    ];

    /**
     * Human-readable labels for each status key.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        OrderStatusEnum::PENDING->value => 'Pending',
        OrderStatusEnum::PLACED->value => 'Order placed',
        OrderStatusEnum::PROCESSED->value => 'Processed',
        OrderStatusEnum::IN_TRANSIT->value => 'In transit',
        OrderStatusEnum::ARRIVED_IN_FACILITY->value => 'Arrived in facility',
        OrderStatusEnum::OUT_FOR_DELIVERY->value => 'Out for delivery',
        OrderStatusEnum::DELIVERED->value => 'Delivered',
        OrderStatusEnum::CANCELLED->value => 'Cancelled',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['order:read', 'assignDelivery:read', 'product_return:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['order:write', 'order:read', 'product_return:read'])]
    private ?float $orderPrice = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?User $customer = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read', 'assignDelivery:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $fullName = null;


    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read', 'assignDelivery:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $street = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read', 'assignDelivery:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $city = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:write', 'order:read', 'assignDelivery:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $state = null;

    #[ORM\Column]
    #[Groups(['order:write', 'order:read', 'assignDelivery:read', 'product_return:read'])]
    private ?DateTime $createdAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:read', 'order:edit', 'assignDelivery:read', 'assignDelivery:driver'])]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'order:edit', 'assignDelivery:read'])]
    private ?DateTime $updatedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['order:read', 'assignDelivery:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $uid = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'currentOrder', orphanRemoval: true)]
    #[Groups(['order:read'])]
    private Collection $products;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'assignDelivery:read'])]
    private ?DateTime $estimatedDeliveryAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $suite = null;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'userOrder', orphanRemoval: true)]
    #[Groups(['order:read:details'])]
    private Collection $payments;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'assignDelivery:read', 'product_return:read'])]
    private ?bool $isPriority = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'product_return:read'])]
    private ?float $discount = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'product_return:read'])]
    private ?float $taxes = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'product_return:read'])]
    private ?float $shippingFee = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read', 'order:edit', 'assignDelivery:read', 'assignDelivery:driver', 'product_return:read'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255)]
    private ?string $accessToken = null;

    // Idempotency lock for checkout: the client sends one key per checkout attempt
    // (Idempotency-Key header). The UNIQUE constraint makes "one order per key"
    // atomic, so a retried or concurrent /initialize-payment reuses this order
    // instead of creating a second one and charging again. Nullable so older orders
    // and clients that do not send a key are unaffected.
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $idempotencyKey = null;

    // The stable Paystack transaction reference for this order. Stored so a retry
    // reuses the exact same reference, letting Paystack's own reference-idempotency
    // refuse a second charge.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paystackReference = null;

    // The Paystack hosted-checkout authorization_url, cached so an idempotent replay
    // can resume the same modal without re-initializing (re-initializing the same
    // reference would be rejected by Paystack as a duplicate).
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authorizationUrl = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read', 'product_return:read'])]
    private ?\DateTime $deliveredAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['order:read:admin', 'assignDelivery:read'])]
    private ?string $fulfillmentNote = null;

    /**
     * @var Collection<int, OrderProcessed>
     */
    #[ORM\OneToMany(targetEntity: OrderProcessed::class, mappedBy: 'currentOrder')]
    #[Groups(['order:read:admin'])]
    private Collection $orderProcesseds;

    #[ORM\OneToOne(mappedBy: 'assignOrder', cascade: ['persist', 'remove'])]
    #[Groups(['order:read'])]
    private ?AssignDelivery $assignDelivery = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isPaidSuccessfully = null;

    /**
     * The promo code applied when the order was created, kept so the code can be
     * consumed once payment is confirmed (the discount itself is already baked into
     * orderPrice/discount). Null when no promo code was used.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read:admin', 'product_return:read'])]
    private ?string $promoCode = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isPaymentFailed = null;

    /**
     * @var Collection<int, ProductReturn>
     */
    #[ORM\OneToMany(targetEntity: ProductReturn::class, mappedBy: 'returnOrder')]
    private Collection $productReturns;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->status = OrderStatusEnum::PENDING->value;
        $this->products = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->orderProcesseds = new ArrayCollection();
        $this->productReturns = new ArrayCollection();
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

    /**
     * The full tracking timeline: every state the order moves through, each
     * flagged as already reached and whether it is the current one. Lets the
     * client render the complete history rather than only the latest status.
     *
     * @return list<array{key: string, label: string, reached: bool, current: bool}>
     */
    #[Groups(['order:read:tracking'])]
    public function getTrackingHistory(): array
    {
        $currentIndex = array_search($this->status, self::TRACKING_FLOW, true);

        $history = [];
        foreach (self::TRACKING_FLOW as $index => $state) {
            $history[] = [
                'key' => $state,
                'label' => self::STATUS_LABELS[$state],
                'reached' => false !== $currentIndex && $index <= $currentIndex,
                'current' => $index === $currentIndex,
            ];
        }

        return $history;
    }

    /**
     * Call-to-action rendered under the tracking history. Inert for now: the
     * client shows the label but no action is wired to it yet.
     *
     * @return array{label: string, action: null}
     */
    #[Groups(['order:read:tracking'])]
    public function getNextStepButton(): array
    {
        return [
            'label' => 'Proceed to next steps',
            'action' => null,
        ];
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

    #[Groups(['order:read:admin', 'order:read'])]
    #[SerializedName('isPriority')]
    public function isPriority(): ?bool
    {
        return $this->isPriority;
    }

    public function setIsPriority(?bool $isPriority): static
    {
        $this->isPriority = $isPriority;

        return $this;
    }

    #[Groups(['assignDelivery:driver'])]
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

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getPaystackReference(): ?string
    {
        return $this->paystackReference;
    }

    public function setPaystackReference(?string $paystackReference): static
    {
        $this->paystackReference = $paystackReference;

        return $this;
    }

    public function getAuthorizationUrl(): ?string
    {
        return $this->authorizationUrl;
    }

    public function setAuthorizationUrl(?string $authorizationUrl): static
    {
        $this->authorizationUrl = $authorizationUrl;

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

    public function getFulfillmentNote(): ?string
    {
        return $this->fulfillmentNote;
    }

    public function setFulfillmentNote(?string $fulfillmentNote): static
    {
        $this->fulfillmentNote = $fulfillmentNote;

        return $this;
    }

    /**
     * @return Collection<int, OrderProcessed>
     */
    public function getOrderProcesseds(): Collection
    {
        return $this->orderProcesseds;
    }

    public function addOrderProcessed(OrderProcessed $orderProcessed): static
    {
        if (!$this->orderProcesseds->contains($orderProcessed)) {
            $this->orderProcesseds->add($orderProcessed);
            $orderProcessed->setCurrentOrder($this);
        }

        return $this;
    }

    public function removeOrderProcessed(OrderProcessed $orderProcessed): static
    {
        if ($this->orderProcesseds->removeElement($orderProcessed)) {
            // set the owning side to null (unless already changed)
            if ($orderProcessed->getCurrentOrder() === $this) {
                $orderProcessed->setCurrentOrder(null);
            }
        }

        return $this;
    }

    public function getAssignDelivery(): ?AssignDelivery
    {
        return $this->assignDelivery;
    }

    public function setAssignDelivery(AssignDelivery $assignDelivery): static
    {
        // set the owning side of the relation if necessary
        if ($assignDelivery->getAssignOrder() !== $this) {
            $assignDelivery->setAssignOrder($this);
        }

        $this->assignDelivery = $assignDelivery;

        return $this;
    }

    public function isPaidSuccessfully(): ?bool
    {
        return $this->isPaidSuccessfully;
    }

    public function setIsPaidSuccessfully(?bool $isPaidSuccessfully): static
    {
        $this->isPaidSuccessfully = $isPaidSuccessfully;

        return $this;
    }

    public function getPromoCode(): ?string
    {
        return $this->promoCode;
    }

    public function setPromoCode(?string $promoCode): static
    {
        $this->promoCode = $promoCode;

        return $this;
    }

    public function isPaymentFailed(): ?bool
    {
        return $this->isPaymentFailed;
    }

    public function setIsPaymentFailed(?bool $isPaymentFailed): static
    {
        $this->isPaymentFailed = $isPaymentFailed;

        return $this;
    }

    /**
     * @return Collection<int, ProductReturn>
     */
    public function getProductReturns(): Collection
    {
        return $this->productReturns;
    }

    public function addProductReturn(ProductReturn $productReturn): static
    {
        if (!$this->productReturns->contains($productReturn)) {
            $this->productReturns->add($productReturn);
            $productReturn->setReturnOrder($this);
        }

        return $this;
    }

    public function removeProductReturn(ProductReturn $productReturn): static
    {
        if ($this->productReturns->removeElement($productReturn)) {
            // set the owning side to null (unless already changed)
            if ($productReturn->getReturnOrder() === $this) {
                $productReturn->setReturnOrder(null);
            }
        }

        return $this;
    }
}