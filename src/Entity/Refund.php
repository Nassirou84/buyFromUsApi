<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefundRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Serializer\Attribute\Groups;

use function in_array;

use const DATE_ATOM;

/**
 * Audit record of money sent back to a customer through the payment gateway.
 *
 * The amounts, payment, currency, reason and initiator are fixed at construction and
 * have no setters: a refund row is never edited, only its gateway status moves forward
 * (each change is appended to $statusHistory). A failed attempt stays as-is and a retry
 * creates a new row with a new reference.
 *
 * Amounts are in the main currency unit (XOF has no minor unit); PaystackService
 * converts to the gateway subunit.
 */
#[ORM\Entity(repositoryClass: RefundRepository::class)]
#[ORM\Index(name: 'idx_refund_status', fields: ['status'])]
#[ORM\Index(name: 'idx_refund_gateway_id', fields: ['refundTransactionNumber'])]
class Refund
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    /** Outcome unknown (timeout, gateway "needs-attention"): must be reconciled before any retry. */
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    /** Statuses that still hold (or may hold) money against the payment. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_PROCESSED,
        self::STATUS_NEEDS_ATTENTION,
    ];

    public const REASON_PRODUCT_RETURN = 'product_return';
    public const REASON_ORDER_CANCELLATION = 'order_cancellation';
    public const REASON_GOODWILL = 'goodwill';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    /** Our own reference, unique per attempt; doubles as the idempotency key. */
    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['product_return:read:admin'])]
    private string $reference;

    /** Refund id returned by the gateway; null until the gateway accepted the request. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?string $refundTransactionNumber = null;

    #[ORM\Column(length: 30)]
    #[Groups(['product_return:read:admin'])]
    private string $provider;

    /** Payment channel of the original charge (card, mobile_money, ...). */
    #[ORM\Column(length: 30)]
    #[Groups(['product_return:read:admin'])]
    private string $channel;

    /** Reference of the original gateway transaction being refunded. */
    #[ORM\Column(length: 255)]
    #[Groups(['product_return:read:admin'])]
    private string $transactionReference;

    #[ORM\Column(length: 3)]
    #[Groups(['product_return:read:admin'])]
    private string $currency;

    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    private int $itemsAmount;

    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    private int $shippingAmount;

    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    private int $taxAmount;

    /** Tax rate (percent) applied on the original payment, copied for the record. */
    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    private int $taxRate;

    /** Total sent to the gateway: items + shipping + tax. */
    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    private int $amount;

    #[ORM\Column(length: 30)]
    #[Groups(['product_return:read:admin'])]
    private string $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?string $note;

    #[ORM\Column(length: 20)]
    #[Groups(['product_return:read:admin'])]
    private string $status = self::STATUS_PENDING;

    /** Raw status string last reported by the gateway. */
    #[Groups(['product_return:read:admin'])]
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $gatewayStatus = null;

    /** @var array<string, mixed>|null Last raw gateway payload (API response or webhook data). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $gatewayResponse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?string $failureReason = null;

    /** @var list<array{status: string, at: string, source: string, gatewayStatus: ?string, by?: ?int, note?: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $statusHistory = [];

    #[ORM\ManyToOne(inversedBy: 'refunds')]
    #[ORM\JoinColumn(nullable: false)]
    private Payment $payment;

    #[ORM\ManyToOne(inversedBy: 'refunds')]
    private ?ProductReturn $productReturn;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $initiatedBy;

    #[ORM\Column]
    #[Groups(['product_return:read:admin'])]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?DateTimeImmutable $failedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_return:read:admin'])]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $reference,
        Payment $payment,
        User $initiatedBy,
        string $reason,
        int $itemsAmount,
        int $shippingAmount,
        int $taxAmount,
        ?ProductReturn $productReturn = null,
        ?string $note = null,
    ) {
        if ($itemsAmount < 0 || $shippingAmount < 0 || $taxAmount < 0) {
            throw new InvalidArgumentException('refund_amount_negative');
        }
        $amount = $itemsAmount + $shippingAmount + $taxAmount;
        if ($amount <= 0) {
            throw new InvalidArgumentException('refund_amount_must_be_positive');
        }

        $this->reference = $reference;
        $this->payment = $payment;
        $this->initiatedBy = $initiatedBy;
        $this->reason = $reason;
        $this->itemsAmount = $itemsAmount;
        $this->shippingAmount = $shippingAmount;
        $this->taxAmount = $taxAmount;
        $this->amount = $amount;
        $this->taxRate = $payment->getTaxRate();
        $this->currency = strtoupper((string) ($payment->getCurrencyCode() ?: 'XOF'));
        $this->provider = (string) ($payment->getPaymentProvider() ?: 'unknown');
        $this->channel = (string) ($payment->getPaymentMethod() ?: 'unknown');
        $this->transactionReference = (string) $payment->getTransactionId();
        $this->productReturn = $productReturn;
        $this->note = $note;
        $this->createdAt = new DateTimeImmutable();

        $payment->getRefunds()->add($this);
        $productReturn?->getRefunds()->add($this);

        $this->recordStatus(self::STATUS_PENDING, 'created', null);
    }

    /**
     * The gateway accepted the request (it may still be processing it asynchronously).
     *
     * @param array<string, mixed> $response
     */
    public function markSubmitted(?string $gatewayRefundId, string $status, ?string $gatewayStatus, array $response, string $source = 'api', ?string $failureReason = null): void
    {
        $this->refundTransactionNumber ??= $gatewayRefundId;
        $this->submittedAt ??= new DateTimeImmutable();
        $this->applyGatewayStatus($status, $gatewayStatus, $response, $source, $failureReason);
    }

    /**
     * Applies a status reported by the gateway (API response or webhook).
     * Terminal statuses (processed, failed) are never left.
     *
     * @param array<string, mixed> $payload
     */
    public function applyGatewayStatus(string $status, ?string $gatewayStatus, array $payload, string $source, ?string $failureReason = null): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $now = new DateTimeImmutable();
        $this->gatewayStatus = $gatewayStatus;
        $this->gatewayResponse = $payload;
        $this->updatedAt = $now;

        if (self::STATUS_PROCESSED === $status) {
            $this->processedAt = $now;
        }
        if (self::STATUS_FAILED === $status || self::STATUS_NEEDS_ATTENTION === $status) {
            $this->failureReason = $failureReason ?? $this->failureReason;
        }
        if (self::STATUS_FAILED === $status) {
            $this->failedAt = $now;
        }

        if ($status !== $this->status) {
            $this->status = $status;
            $this->recordStatus($status, $source, $gatewayStatus);
        }
    }

    /**
     * Closes a `needs_attention` refund by hand, once an admin has checked the outcome
     * on the gateway side. The gateway response stays as it was; who resolved it and why
     * go into the status history.
     */
    public function resolveManually(string $status, User $resolvedBy, string $note): void
    {
        if (self::STATUS_NEEDS_ATTENTION !== $this->status) {
            throw new LogicException('refund_not_awaiting_reconciliation');
        }
        if (!in_array($status, [self::STATUS_PROCESSED, self::STATUS_FAILED], true)) {
            throw new InvalidArgumentException('refund_resolution_invalid');
        }

        $now = new DateTimeImmutable();
        $this->status = $status;
        $this->updatedAt = $now;
        if (self::STATUS_PROCESSED === $status) {
            $this->processedAt = $now;
        } else {
            $this->failedAt = $now;
            $this->failureReason = 'resolved by admin: ' . $note;
        }
        $this->recordStatus($status, 'admin', $this->gatewayStatus, [
            'by' => $resolvedBy->getId(),
            'note' => $note,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSED, self::STATUS_FAILED], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * @param array{by?: ?int, note?: string} $extra
     */
    private function recordStatus(string $status, string $source, ?string $gatewayStatus, array $extra = []): void
    {
        $this->statusHistory[] = [
            'status' => $status,
            'at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'source' => $source,
            'gatewayStatus' => $gatewayStatus,
        ] + $extra;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getRefundTransactionNumber(): ?string
    {
        return $this->refundTransactionNumber;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getTransactionReference(): string
    {
        return $this->transactionReference;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getItemsAmount(): int
    {
        return $this->itemsAmount;
    }

    public function getShippingAmount(): int
    {
        return $this->shippingAmount;
    }

    public function getTaxAmount(): int
    {
        return $this->taxAmount;
    }

    public function getTaxRate(): int
    {
        return $this->taxRate;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getGatewayStatus(): ?string
    {
        return $this->gatewayStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGatewayResponse(): ?array
    {
        return $this->gatewayResponse;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * @return list<array{status: string, at: string, source: string, gatewayStatus: ?string, by?: ?int, note?: string}>
     */
    public function getStatusHistory(): array
    {
        return $this->statusHistory;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function getProductReturn(): ?ProductReturn
    {
        return $this->productReturn;
    }

    public function getInitiatedBy(): User
    {
        return $this->initiatedBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSubmittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getFailedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
