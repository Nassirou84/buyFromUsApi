<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProductReturn;
use App\Entity\Refund;
use App\Entity\User;
use App\Repository\RefundRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use function count;
use function in_array;
use function is_string;
use function strlen;

/**
 * Gateway refunds: writes the audit row before calling the gateway, then records
 * what the gateway answered (API response, then webhooks).
 */
final class RefundService
{
    /** Payment statuses meaning the money was actually collected. */
    private const SETTLED_PAYMENT_STATUSES = ['success', Payment::STATUS_COMPLETED];

    private const GATEWAY_STATUS_MAP = [
        'pending' => Refund::STATUS_PENDING,
        'processing' => Refund::STATUS_PROCESSING,
        'processed' => Refund::STATUS_PROCESSED,
        'failed' => Refund::STATUS_FAILED,
        'needs-attention' => Refund::STATUS_NEEDS_ATTENTION,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RefundRepository $refundRepository,
        private PaystackService $paystackService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The successful Paystack payment of an order, or null when there is none.
     */
    public function findRefundablePayment(Order $order): ?Payment
    {
        $settled = null;
        foreach ($order->getPayments() as $payment) {
            if (!in_array($payment->getStatus(), self::SETTLED_PAYMENT_STATUSES, true)) {
                continue;
            }
            if ('paystack' !== $payment->getPaymentProvider()) {
                continue;
            }
            if (null === $settled || $payment->getCreatedAt() > $settled->getCreatedAt()) {
                $settled = $payment;
            }
        }

        return $settled;
    }

    /**
     * What can still be refunded on a payment: amount collected minus every refund
     * that is pending, processing, processed or of unknown outcome.
     */
    public function getRefundableAmount(Payment $payment): int
    {
        $alreadyRefunded = 0;
        foreach ($payment->getRefunds() as $refund) {
            if ($refund->isActive()) {
                $alreadyRefunded += $refund->getAmount();
            }
        }

        return max((int) floor((float) $payment->getAmount()) - $alreadyRefunded, 0);
    }

    public function hasActiveRefund(ProductReturn $return): bool
    {
        foreach ($return->getRefunds() as $refund) {
            if ($refund->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refunds a product return through the gateway.
     *
     * The Refund row is flushed as `pending` before the gateway is called, so an attempt
     * is always on record even if the request dies. Gateway outcome:
     * - accepted: status taken from the gateway (pending/processing/processed); webhooks finish it.
     * - refused (4xx): row marked `failed`, throws `refund_rejected`. A retry creates a new row.
     * - unknown (timeout/5xx): row marked `needs_attention`, throws `refund_outcome_unknown`.
     *   It counts against the refundable amount and blocks retries until reconciled.
     *
     * @throws LogicException   when the refund is not allowed (nothing written)
     * @throws RuntimeException when the gateway refused it or its outcome is unknown
     */
    public function refundProductReturn(
        ProductReturn $return,
        Payment $payment,
        User $initiatedBy,
        int $itemsAmount,
        int $shippingAmount,
        int $taxAmount,
    ): Refund {
        if ($this->hasActiveRefund($return)) {
            throw new LogicException('refund_already_exists_for_return');
        }

        // Rounding (prices ceiled per component) can overshoot a full refund by one unit:
        // absorb that on the items line, refuse anything larger.
        $overshoot = ($itemsAmount + $shippingAmount + $taxAmount) - $this->getRefundableAmount($payment);
        if ($overshoot > 1) {
            throw new LogicException('refund_exceeds_refundable_amount');
        }
        if ($overshoot > 0) {
            $itemsAmount -= $overshoot;
        }

        $refund = new Refund(
            reference: $this->buildReference($return),
            payment: $payment,
            initiatedBy: $initiatedBy,
            reason: Refund::REASON_PRODUCT_RETURN,
            itemsAmount: $itemsAmount,
            shippingAmount: $shippingAmount,
            taxAmount: $taxAmount,
            productReturn: $return,
            note: $return->getReturnNote(),
        );

        try {
            $this->entityManager->persist($refund);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the same attempt first.
            throw new LogicException('refund_already_exists_for_return');
        }

        try {
            $data = $this->paystackService->refund(
                $refund->getTransactionReference(),
                $refund->getAmount(),
                $refund->getCurrency(),
                'Return ' . $return->getUid() . ' / refund ' . $refund->getReference(),
            );
        } catch (DomainException $e) {
            $refund->applyGatewayStatus(Refund::STATUS_FAILED, null, ['error' => $e->getMessage()], 'api', $e->getMessage());
            $this->entityManager->flush();

            throw new RuntimeException('refund_rejected', 0, $e);
        } catch (TransportExceptionInterface|RuntimeException $e) {
            $refund->applyGatewayStatus(Refund::STATUS_NEEDS_ATTENTION, null, ['error' => $e->getMessage()], 'api', $e->getMessage());
            $this->entityManager->flush();
            $this->logger->critical('Refund outcome unknown, reconcile before retrying', [
                'reference' => $refund->getReference(),
                'transaction' => $refund->getTransactionReference(),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('refund_outcome_unknown', 0, $e);
        }

        $gatewayStatus = is_string($data['status'] ?? null) ? $data['status'] : null;
        $refund->markSubmitted(
            isset($data['id']) ? (string) $data['id'] : null,
            $this->mapGatewayStatus($gatewayStatus) ?? Refund::STATUS_PENDING,
            $gatewayStatus,
            $data,
        );
        $this->entityManager->flush();

        return $refund;
    }

    /**
     * Applies a Paystack `refund.*` webhook. Returns false when no matching refund exists.
     *
     * @param array<string, mixed> $data
     */
    public function applyGatewayWebhook(string $event, array $data): bool
    {
        $gatewayStatus = is_string($data['status'] ?? null) ? $data['status'] : substr($event, strlen('refund.'));
        $status = $this->mapGatewayStatus($gatewayStatus);
        if (null === $status) {
            return false;
        }

        $gatewayId = isset($data['id']) ? (string) $data['id'] : null;
        $refund = null !== $gatewayId ? $this->refundRepository->findOneBy(['refundTransactionNumber' => $gatewayId]) : null;
        $refund ??= $this->findUnresolvedByTransaction($data);

        if (null === $refund) {
            $this->logger->warning('Refund webhook with no matching refund', ['event' => $event, 'id' => $gatewayId]);

            return false;
        }

        $failureReason = Refund::STATUS_FAILED === $status ? 'gateway reported ' . $gatewayStatus : null;
        if (null === $refund->getRefundTransactionNumber() && null !== $gatewayId) {
            // Request had timed out on our side: the webhook tells us it did reach Paystack.
            $refund->markSubmitted($gatewayId, $status, $gatewayStatus, $data);
        } else {
            $refund->applyGatewayStatus($status, $gatewayStatus, $data, 'webhook', $failureReason);
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * Fallback match for a webhook whose refund id we never stored (timed-out request):
     * the single unresolved refund on that transaction with the same amount.
     *
     * @param array<string, mixed> $data
     */
    private function findUnresolvedByTransaction(array $data): ?Refund
    {
        $transactionReference = $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null);
        if (!is_string($transactionReference) || !is_numeric($data['amount'] ?? null)) {
            return null;
        }
        $amount = (int) round(((float) $data['amount']) / 100);

        $candidates = array_values(array_filter(
            $this->refundRepository->findBy(['transactionReference' => $transactionReference]),
            static fn (Refund $refund): bool => !$refund->isTerminal()
                && null === $refund->getRefundTransactionNumber()
                && $refund->getAmount() === $amount,
        ));

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    private function mapGatewayStatus(?string $gatewayStatus): ?string
    {
        return null === $gatewayStatus ? null : (self::GATEWAY_STATUS_MAP[strtolower($gatewayStatus)] ?? null);
    }

    /**
     * Deterministic per attempt (RF-<return uid>-<n>) so two concurrent requests collide on
     * the unique index instead of both reaching the gateway.
     */
    private function buildReference(ProductReturn $return): string
    {
        $reference = 'RF-' . $return->getUid() . '-' . ($return->getRefunds()->count() + 1);

        return strlen($reference) <= 64 ? $reference : 'RF-' . substr(hash('sha256', $reference), 0, 40);
    }
}
