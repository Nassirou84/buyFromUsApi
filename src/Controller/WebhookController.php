<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PaymentFinalizationService;
use App\Service\SecretManagerService;
use App\Service\WebhookEventService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;
use function is_array;

final class WebhookController extends AbstractController
{
    public function __construct(
        private readonly SecretManagerService $secretManager,
        private readonly PaymentFinalizationService $paymentFinalizer,
        private readonly WebhookEventService $webhookEvents,
        // Comma-separated allowlist of Paystack webhook source IPs. Empty = disabled
        // (HMAC remains the gate). Enabling it requires correct trusted-proxy config
        // so getClientIp() returns the real sender, not a proxy.
        private readonly string $allowedWebhookIps,
    ) {
    }

    #[Route('/api/paystack/webhook', name: 'paystack_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        // Defense-in-depth before the HMAC: if an allowlist is configured, the call
        // must come from a known Paystack IP. Disabled when the list is empty.
        if (!$this->isAllowedSource($request)) {
            return new JsonResponse(['error' => 'Forbidden source'], Response::HTTP_FORBIDDEN);
        }

        $rawPayload = $request->getContent();
        $signature = $request->headers->get('x-paystack-signature');

        if (!$signature) {
            return new JsonResponse(['error' => 'Missing signature'], Response::HTTP_BAD_REQUEST);
        }

        $secretKey = $this->secretManager->getSecret('paystack_test_secret_key');
        $computedSignature = hash_hmac('sha512', $rawPayload, $secretKey);

        if (!hash_equals($computedSignature, $signature)) {
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        $eventData = json_decode($rawPayload, true);
        $event = is_array($eventData) ? ($eventData['event'] ?? null) : null;
        $data = is_array($eventData['data'] ?? null) ? $eventData['data'] : [];

        // Event-level idempotency: a stable key per event, so a replay of ANY event
        // type short-circuits here — not just a charge whose Payment already exists.
        $eventKey = $this->eventKey($event, $data, $rawPayload);
        if ($this->webhookEvents->alreadyProcessed($eventKey)) {
            return $this->ack();
        }

        if ('charge.success' === $event) {
            $reference = $data['reference'] ?? null;

            if ($reference) {
                // The same finalizer the verify and inline-charge paths use: it is
                // idempotent (an already-recorded reference is a no-op) and records the
                // Payment with every field set, confirms the order and sends the email.
                // This is the self-sufficient confirmation path when the customer never
                // returns to trigger verify-payment. Left to throw on failure so the
                // event is NOT marked processed and Paystack retries it.
                $this->paymentFinalizer->finalize($data, (string) $reference);
            }
        }

        // Record only after successful handling, so a transient failure above leaves
        // the event un-recorded and retryable.
        $this->webhookEvents->markProcessed($eventKey);

        return $this->ack();
    }

    /**
     * True when no allowlist is configured, or the request's client IP is on it.
     */
    private function isAllowedSource(Request $request): bool
    {
        $allowed = array_values(array_filter(array_map('trim', explode(',', $this->allowedWebhookIps))));
        if ([] === $allowed) {
            return true;
        }

        return in_array((string) $request->getClientIp(), $allowed, true);
    }

    /**
     * Stable idempotency key for an event: the event name plus the most stable
     * identifier available in its data (the resource id, then the reference), and
     * finally a hash of the raw body so even an id-less event still dedupes.
     *
     * @param array<string, mixed> $data
     */
    private function eventKey(?string $event, array $data, string $rawPayload): string
    {
        $identifier = $data['id'] ?? $data['reference'] ?? substr(hash('sha256', $rawPayload), 0, 40);

        return ($event ?? 'unknown') . ':' . $identifier;
    }

    private function ack(): JsonResponse
    {
        // Always acknowledge with 200 so Paystack does not retry an event we have
        // already processed (or one we intentionally ignore).
        return new JsonResponse([
            'status' => 'success',
            'message' => 'Webhook processed successfully',
        ]);
    }
}
