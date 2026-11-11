<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Payment;
use App\Repository\OrderRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;

use function is_array;
use function is_string;

/**
 * Single place where a successful Paystack transaction is turned into a confirmed
 * order + recorded Payment. All three entry points go through here so they behave
 * identically and cannot drift apart:
 *   - the hosted-checkout verify flow (PaystackPaymentController::verifyPayment),
 *   - the server-to-server webhook (WebhookController),
 *   - the saved-card inline charge (PaystackPaymentController::initializePayment).
 *
 * It is idempotent (safe to call more than once for the same reference) and
 * tolerant of the verify/webhook race on the Payment unique index.
 */
final class PaymentFinalizationService
{
    /**
     * A paid amount is accepted when it is at least the order total, minus this
     * slack in the currency subunit to absorb float rounding between the amount
     * sent at initialization ((int) cast) and the amount Paystack echoes back.
     */
    private const AMOUNT_TOLERANCE_SUBUNIT = 1;

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderService $orderService,
        private readonly PaymentService $paymentService,
        private readonly PaymentMethodService $paymentMethodService,
    ) {
    }

    /**
     * Finalizes a Paystack transaction `data` object (the same shape is returned by
     * /transaction/verify, /transaction/charge_authorization and the charge.success
     * webhook).
     *
     * @return array{outcome: string, details: array<string, mixed>|null}
     *                                                                    outcome is one of: finalized | already_processed |
     *                                                                    order_not_found | amount_mismatch. `details` carries the
     *                                                                    order payload for the client when the order is known.
     */
    /**
     * Builds the client order-details payload for an order that is already paid,
     * used when an idempotent checkout replay must return the confirmation again
     * without touching Paystack.
     *
     * @return array<string, mixed>
     */
    public function orderDetails(Order $order): array
    {
        return $this->buildDetails($order, (string) $order->getPaystackReference(), 'success');
    }

    public function finalize(array $txData, string $reference): array
    {
        $metadata = is_array($txData['metadata'] ?? null) ? $txData['metadata'] : [];
        $orderUid = $metadata['order_uid'] ?? null;

        if (empty($orderUid)) {
            return ['outcome' => 'order_not_found', 'details' => null];
        }

        $order = $this->orderRepository->findOneBy(['uid' => $orderUid]);
        if (null === $order) {
            return ['outcome' => 'order_not_found', 'details' => null];
        }

        $status = $txData['status'] ?? null;

        // Idempotency (common case): the reference was already recorded by a prior
        // verify/webhook/charge. Return the order details again rather than erroring
        // or inserting a duplicate, so a second verify still drives the success UI.
        if ($this->paymentService->existingPayment($reference)) {
            return ['outcome' => 'already_processed', 'details' => $this->buildDetails($order, $reference, $status)];
        }

        // Only a settled, successful transaction may confirm an order. Mobile-money
        // charges sit in `pending` / `pay_offline` while the customer approves on
        // their phone, and the client polls verify meanwhile — a non-success status
        // must never confirm the order or record a payment.
        if ('success' !== $status) {
            return ['outcome' => 'pending', 'details' => null];
        }

        // Never fulfil an order for less than it costs: compare the amount Paystack
        // actually settled (currency subunit) against the authoritative order total.
        $paidSubunit = (int) ($txData['amount'] ?? 0);
        $expectedSubunit = (int) round((float) $order->getOrderPrice() * 100);
        if ($paidSubunit + self::AMOUNT_TOLERANCE_SUBUNIT < $expectedSubunit) {
            $this->orderService->markOrderAsFailed((string) $orderUid);

            return ['outcome' => 'amount_mismatch', 'details' => null];
        }

        // Confirm the order as paid (sets PLACED + paid flag; no-op if already paid).
        $order = $this->orderService->confirmOrderAsPaid((string) $orderUid);

        // Build the client payload while the entity manager is healthy (items is a
        // lazy collection), so it stays available even if the insert below races.
        $details = $this->buildDetails($order, $reference, $status);

        $payment = $this->buildPayment($txData, $reference, $order);

        try {
            $this->paymentService->createPayment($payment);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent verify/webhook inserted the Payment between our existence
            // check and this flush. The other path owns the method-save and email;
            // the order is already paid, so just report it as processed.
            return ['outcome' => 'already_processed', 'details' => $details];
        }

        // Store the card for reuse against the ORDER'S OWNER, never a user merely
        // matched by the payer email — that would let a guest attach a card to a
        // stranger's account. Cards only: mobile money has no reusable charge
        // token, so there is nothing worth saving (createFromAuthorization
        // enforces that too).
        $saveCard = (bool) ($metadata['save_card'] ?? false);
        $authorization = $txData['authorization'] ?? null;
        $customer = $order->getCustomer();
        if ($saveCard && null !== $customer && is_array($authorization)) {
            $this->paymentMethodService->createFromAuthorization($authorization, $customer);
        }

        // Confirmation email is best-effort: a mail failure must not fail a payment
        // that already went through.
        try {
            $this->orderService->sendOrderConfirmation($order, $payment);
        } catch (Throwable $e) {
            // Order is paid and recorded; email can be resent later.
        }

        return ['outcome' => 'finalized', 'details' => $details];
    }

    /**
     * @param array<string, mixed> $txData
     */
    private function buildPayment(array $txData, string $reference, Order $order): Payment
    {
        $authorization = $txData['authorization'] ?? null;
        $amount = ((float) ($txData['amount'] ?? 0)) / 100;
        $fees = ((float) ($txData['fees'] ?? 0)) / 100;
        $currency = $txData['currency'] ?? 'XOF';
        $status = $txData['status'] ?? 'success';
        $channel = $txData['channel'] ?? null;

        $payment = new Payment();
        $payment->setTransactionId($reference);
        $payment->setAmount($amount);
        $payment->setCurrencyCode(is_string($currency) ? $currency : 'XOF');
        $payment->setStatus(is_string($status) ? $status : 'success');
        $payment->setTaxedAmount($amount * 0.18);
        $payment->setPaymentMethod(is_string($channel) ? $channel : 'unknown');
        $payment->setPaymentProvider('paystack');
        if ('card' === $channel && is_array($authorization)) {
            $payment->setPaymentCard($authorization['last4'] ?? null);
        } elseif ('mobile_money' === $channel && is_array($authorization)) {
            $payment->setMobileMoney($authorization['mobile_money_number'] ?? null);
        }
        $payment->setFees($fees);
        $payment->setUserOrder($order);

        return $payment;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDetails(Order $order, string $reference, ?string $status): array
    {
        $items = [];
        foreach ($order->getProducts() as $item) {
            $items[] = [
                'productName' => $item->getProduct()->getTitle(),
                'quantity' => $item->getQuantity(),
            ];
        }

        return [
            'success' => true,
            'status' => $status,
            'reference' => $reference,
            'orderNumber' => $order->getUid(),
            'email' => $order->getEmail(),
            'estimatedDeliveryAt' => $order->getEstimatedDeliveryAt()?->format('Y-m-d H:i:s'),
            'totalAmount' => $order->getOrderPrice(),
            'fullAddress' => $order->getFullAddress(),
            'items' => $items,
        ];
    }
}
