<?php

namespace App\Controller;

use App\Entity\Order;
use App\Repository\BasketRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentMethodRepository;
use App\Service\OrderService;
use App\Service\PaymentFinalizationService;
use App\Service\PaymentService;
use App\Service\PaystackService;
use App\Service\SecretManagerService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('api/paystack')]
final class PaystackPaymentController extends AbstractController
{
    // Mobile money is a single checkout choice: the Paystack checkout collects the
    // wallet (Wave / Orange / MTN / ...) and the phone number itself, so we never
    // pre-select a provider or handle a number server-side.
    private const CHANNELS = [
        'card' => 'card',
        'mobile_money' => 'mobile_money',
    ];

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly OrderRepository $orderRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly OrderService $orderService,
        private readonly PaystackService $paystackService,
        private readonly PaymentService $paymentService,
        private readonly PaymentMethodRepository $paymentMethodRepository,
        private readonly SecretManagerService $secretManager,
        private readonly PaymentFinalizationService $paymentFinalizer,
    ) {
    }

    #[Route('/initialize-payment', name: 'app_paystack_payment')]
    public function initializePayment(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $basketUid = $data['basketUid'] ?? null;
        $promoCode = $data['promoCode'] ?? null;
        $shippingAddress = is_array($data['shippingAddress'] ?? null) ? $data['shippingAddress'] : [];
        $idempotencyKey = $this->resolveIdempotencyKey($request, $data);

        // Everything the checkout needs once an order exists, bundled so the fresh
        // path and the idempotent replay path share the exact same handling.
        $checkout = [
            'savedPaymentMethodId' => $data['savedPaymentMethodId'] ?? null,
            'selectedPaymentMethod' => $data['paymentMethod'] ?? null,
            'shippingAddress' => $shippingAddress,
            // Cards only: mobile money carries no reusable charge token, so there
            // is nothing to save for future purchases.
            'saveCard' => (bool) ($data['saveCard'] ?? false),
        ];

        $basket = $this->basketRepository->findOneBy(['uid' => $basketUid]);
        if (null === $basket) {
            return new JsonResponse(['success' => false, 'message' => 'basket_not_found'], 404);
        }

        $currentUser = $this->tokenStorage->getToken()?->getUser();
        if ($basket->getUser() && $basket->getUser() !== $currentUser) {
            return new JsonResponse(['success' => false, 'message' => 'basket_not_found'], 404);
        }

        // Idempotency lock, fast path: a previous attempt with this key already
        // produced an order. Resume it instead of creating a second one / charging
        // again. Covers a retried request, a double-submit and a page refresh.
        if (null !== $idempotencyKey) {
            $existing = $this->orderRepository->findOneBy(['idempotencyKey' => $idempotencyKey]);
            if (null !== $existing) {
                return $this->resumeExistingAttempt($existing, $currentUser, $checkout);
            }
        }

        // Fresh attempt: creating the order claims the key. The UNIQUE column is the
        // actual lock — two simultaneous requests both reach here, the database lets
        // exactly one insert through.
        try {
            $order = $this->orderService->createPendingOrder($shippingAddress, $basket, $promoCode, $idempotencyKey);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a genuinely concurrent race for the same key. The failed flush
            // closes the entity manager, so we cannot safely read the winner here;
            // tell the client to retry — by then the winner's order exists and the
            // fast path above resumes it cleanly.
            return new JsonResponse(['success' => false, 'message' => 'checkout_in_progress'], 409);
        }

        if ($currentUser && $order->getCustomer() && $order->getCustomer() !== $currentUser) {
            return new JsonResponse(['success' => false, 'message' => 'order_not_found'], 404);
        }

        return $this->startCheckout($order, $currentUser, $checkout);
    }

    /**
     * Resumes a checkout for an order that already exists for the idempotency key,
     * so a retry never creates a second order or a second charge.
     *
     * @param array<string, mixed> $checkout
     */
    private function resumeExistingAttempt(Order $order, ?UserInterface $currentUser, array $checkout): JsonResponse
    {
        // The key is a per-customer capability: never resume (or expose) an order
        // that belongs to someone else because a key happened to collide.
        $orderCustomer = $order->getCustomer();
        if (null !== $orderCustomer && $orderCustomer !== $currentUser) {
            return new JsonResponse(['success' => false, 'message' => 'idempotency_key_conflict'], 409);
        }

        // Already paid: return the confirmation again, with no charge.
        if ($order->isPaidSuccessfully()) {
            $details = $this->paymentFinalizer->orderDetails($order);
            $details['charged'] = true;
            $details['order_uid'] = $order->getUid();

            return new JsonResponse($details);
        }

        // Pending hosted checkout that was already initialized: resume the same
        // Paystack modal (re-initializing the same reference would be rejected).
        if (null !== $order->getAuthorizationUrl()) {
            return new JsonResponse([
                'success' => true,
                'reference' => $order->getPaystackReference(),
                'authorization_url' => $order->getAuthorizationUrl(),
                'order_uid' => $order->getUid(),
            ]);
        }

        // Pending and never got as far as charging/initializing (the first attempt
        // failed or raced before it reached Paystack): run it again on the SAME
        // order and SAME reference. A saved-card re-charge is deduplicated by
        // Paystack on the reference, so this cannot double-charge.
        return $this->startCheckout($order, $currentUser, $checkout);
    }

    /**
     * Charges a saved card inline, or initializes a hosted Paystack transaction,
     * for an already-created pending order. Uses the order's stored, stable
     * reference so retries stay idempotent.
     *
     * @param array<string, mixed> $checkout
     */
    private function startCheckout(Order $order, ?UserInterface $currentUser, array $checkout): JsonResponse
    {
        if ($order->isPaidSuccessfully()) {
            return new JsonResponse(['success' => false, 'message' => 'order_already_paid'], 400);
        }

        $savedPaymentMethodId = $checkout['savedPaymentMethodId'];
        $selectedPaymentMethod = $checkout['selectedPaymentMethod'];
        $shippingAddress = $checkout['shippingAddress'];

        $reference = (string) $order->getPaystackReference();
        $email = $shippingAddress['email'] ?? $order->getEmail();
        $channels = [self::CHANNELS[$selectedPaymentMethod] ?? 'card'];

        // The customer picked one of their saved payment methods.
        if (null !== $savedPaymentMethodId) {
            $savedMethod = $this->paymentMethodRepository->findOneBy([
                'id' => $savedPaymentMethodId,
                'customer' => $currentUser,
            ]);

            // Scoped to the current user, so this also blocks using someone else's
            // saved method; a guest (no user) never matches a stored method.
            if (null === $savedMethod) {
                $this->orderService->cleanupFailedOrder($order);

                return new JsonResponse(['success' => false, 'message' => 'payment_method_not_found'], 404);
            }

            // A stored card carries a reusable Paystack authorization token: decrypt it
            // and charge it directly, server-to-server, with no checkout redirect.
            if (null !== $savedMethod->getAuthorizationCode()) {
                if (!$email) {
                    $this->orderService->cleanupFailedOrder($order);

                    return new JsonResponse(['success' => false, 'message' => 'email_missing'], 400);
                }

                try {
                    $charge = $this->paystackService->chargeAuthorization(
                        authorizationCode: $this->secretManager->decryptElement($savedMethod->getAuthorizationCode()),
                        email: $email,
                        amount: $order->getOrderPrice(),
                        reference: $reference,
                        orderUid: $order->getUid()
                    );
                } catch (\Exception $e) {
                    $this->orderService->cleanupFailedOrder($order);

                    return new JsonResponse(
                        ['success' => false, 'message' => 'paystack_charge_failed'],
                        Response::HTTP_BAD_GATEWAY
                    );
                }

                // The API call succeeded but the card was not charged. A reusable
                // authorization can come back needing a step we cannot complete
                // server-side (`send_otp`, `pending`): there is no money to keep and
                // no way to finish here, so tear the order down and tell the client to
                // retry through the hosted checkout.
                $chargeStatus = $charge['status'] ?? null;
                if ('success' !== $chargeStatus) {
                    $this->orderService->cleanupFailedOrder($order);

                    $needsHostedFlow = \in_array($chargeStatus, ['send_otp', 'pending', 'open_url'], true);

                    return new JsonResponse(
                        [
                            'success' => false,
                            'message' => $needsHostedFlow ? 'card_requires_hosted_checkout' : 'card_charge_declined',
                            'status' => $chargeStatus,
                        ],
                        Response::HTTP_PAYMENT_REQUIRED
                    );
                }

                // Charged inline — no redirect. Finalize through the shared service so
                // this path behaves exactly like verify/webhook: confirm the order,
                // record the Payment, save nothing new (the card is already saved),
                // email the confirmation, and return the full order details.
                $result = $this->paymentFinalizer->finalize($charge, $reference);

                if (null === $result['details']) {
                    // Order vanished or amount check failed after a successful charge:
                    // do not leave a paid-but-unconfirmed order silently.
                    return new JsonResponse(
                        ['success' => false, 'message' => $result['outcome']],
                        Response::HTTP_BAD_GATEWAY
                    );
                }

                $details = $result['details'];
                $details['charged'] = true;
                $details['order_uid'] = $order->getUid();

                return new JsonResponse($details);
            }

            // Only cards are saveable, so a saved method without a reusable
            // authorization token cannot be charged. (Mobile money used to land
            // here; it is no longer stored and is entered in the Paystack checkout
            // on every purchase.)
            $this->orderService->cleanupFailedOrder($order);

            return new JsonResponse(
                ['success' => false, 'message' => 'payment_method_not_chargeable'],
                422
            );
        }

        // Everything else — a new card and all mobile money — goes through the
        // Paystack checkout, which collects the card details or the wallet and
        // phone number itself and runs the whole approval in its own UI.
        try {
            $result = $this->paystackService->initializeTransaction(
                email: $email,
                amount: $order->getOrderPrice(),
                reference: $reference,
                channels: $channels,
                saveCard: $checkout['saveCard'],
                orderUid: $order->getUid()
            );
        } catch (\Exception $e) {
            $this->orderService->cleanupFailedOrder($order);

            return new JsonResponse(
                ['success' => false, 'message' => 'paystack_initialization_failed'],
                Response::HTTP_BAD_GATEWAY
            );
        }

        $authorizationUrl = $result['authorization_url'] ?? null;

        // Cache the authorization_url so an idempotent replay resumes this same modal.
        if (null !== $authorizationUrl) {
            $this->orderService->attachAuthorizationUrl($order, $authorizationUrl);
        }

        return new JsonResponse([
            'success' => true,
            'reference' => $reference,
            'authorization_url' => $authorizationUrl,
            'order_uid' => $order->getUid(),
        ]);
    }

    /**
     * Resolves the checkout idempotency key: the `Idempotency-Key` header first
     * (the conventional transport), then an `idempotencyKey` body field. Returns
     * null when absent, in which case checkout keeps its pre-idempotency behaviour.
     *
     * @param array<string, mixed> $data
     */
    private function resolveIdempotencyKey(Request $request, array $data): ?string
    {
        $key = $request->headers->get('Idempotency-Key');
        if (null === $key || '' === $key) {
            $key = $data['idempotencyKey'] ?? null;
        }

        return is_string($key) && '' !== $key ? $key : null;
    }

    /**
     * Tears down the PENDING order (and its items) created up front for a Paystack
     * checkout when the customer aborts the payment — closes the Paystack window or
     * cancels — so abandoned attempts do not pile up as unpayable PENDING rows.
     *
     * Body: the payment `reference` being aborted and the `orderUid` to delete. The
     * reference identifies the Paystack transaction to confirm as not-paid; the
     * orderUid names the pending order (and its items) to remove completely.
     *
     * Access rule: PUBLIC_ACCESS. Authorization is by possession of the unguessable
     * Paystack transaction `reference` (the same capability model as verify-payment),
     * so guest checkouts can abort too; no role is required. The guards only ever
     * BLOCK a confirmed-paid or unrelated order; a reference Paystack does not know
     * (or cannot be verified right now) falls through to the database-guarded cleanup,
     * which itself only removes a still-PENDING, unpaid order. The guards are:
     *  - a Payment is already recorded for the reference (verify/webhook ran), or
     *  - Paystack reports the transaction status as `success`, or
     *  - the reference belongs to a different order than the supplied orderUid.
     */
    #[Route('/abort-payment', name: 'app_paystack_abort_payment', methods: ['POST'])]
    public function abortPayment(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $reference = is_array($data) ? ($data['reference'] ?? null) : null;
        $orderUid = is_array($data) ? ($data['orderUid'] ?? null) : null;

        if (!$reference) {
            return new JsonResponse(['success' => false, 'message' => 'reference_missing'], 400);
        }

        if (!$orderUid) {
            return new JsonResponse(['success' => false, 'message' => 'order_uid_missing'], 400);
        }

        // A recorded Payment means verify-payment or the webhook already ran and the
        // charge went through — never tear down a paid order.
        if ($this->paymentService->existingPayment($reference)) {
            return new JsonResponse(['success' => false, 'message' => 'payment_already_recorded'], 409);
        }

        // Ask Paystack whether the transaction actually succeeded, so a checkout window
        // closed right after a successful charge cannot wipe out the order while our
        // webhook/verify is still in flight. This is best-effort: an unknown reference
        // or an unreachable Paystack must NOT block the cleanup — the only hard stop is
        // an explicit `success`. A brand-new, never-paid reference lands here.
        $paystackStatus = null;
        $referenceOrderUid = null;
        try {
            $txData = $this->paystackService->verifyTransaction($reference);
            $paystackStatus = $txData['status'] ?? null;
            $referenceOrderUid = $txData['metadata']['order_uid'] ?? null;
        } catch (\Throwable $e) {
            // Not verifiable (unknown reference, network error, ...): treat as not-paid
            // and let the database guard below decide. Nothing to block on here.
        }

        if ('success' === $paystackStatus) {
            return new JsonResponse(['success' => false, 'message' => 'payment_succeeded'], 409);
        }

        // When Paystack does know the reference, it must belong to the order being
        // deleted, so a valid reference cannot be turned into a tool for deleting
        // someone else's pending order.
        if (null !== $referenceOrderUid && $referenceOrderUid !== $orderUid) {
            return new JsonResponse(['success' => false, 'message' => 'reference_order_mismatch'], 409);
        }

        $cleaned = $this->orderService->abortPendingOrder((string) $orderUid);

        return new JsonResponse([
            'success' => true,
            'message' => $cleaned ? 'pending_order_cleaned_up' : 'nothing_to_clean_up',
        ]);
    }

    #[Route('/verify-payment', name: 'app_paystack_verify_payment', methods: ['POST'])]
    public function verifyPayment(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $reference = is_array($data) ? ($data['reference'] ?? null) : null;

        if (!$reference) {
            return new JsonResponse(['success' => false, 'message' => 'reference_missing'], 400);
        }

        $txData = $this->paystackService->verifyTransaction($reference);

        $result = $this->paymentFinalizer->finalize($txData, (string) $reference);

        // Already recorded by the webhook or a previous verify: this is a success for
        // the client, not an error — return the order details so the confirmation UI
        // still renders. (Previously this returned 400 payment_already_verified.)
        if (\in_array($result['outcome'], ['finalized', 'already_processed'], true)) {
            return new JsonResponse($result['details']);
        }

        if ('amount_mismatch' === $result['outcome']) {
            return new JsonResponse(['success' => false, 'message' => 'amount_mismatch'], 400);
        }

        // Charge not settled yet (mobile money awaiting phone approval). Not an
        // error: the client polls this endpoint and keeps waiting on this response.
        if ('pending' === $result['outcome']) {
            return new JsonResponse(['success' => false, 'message' => 'payment_pending'], 200);
        }

        return new JsonResponse(['success' => false, 'message' => 'order_not_found'], 404);
    }
}
