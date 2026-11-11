<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PaymentFinalizationService;
use App\Service\SecretManagerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;

final class WebhookController extends AbstractController
{
    #[Route('/api/paystack/webhook', name: 'paystack_webhook', methods: ['POST'])]
    public function handleWebhook(
        Request $request,
        SecretManagerService $secretManagerService,
        PaymentFinalizationService $paymentFinalizer,
    ): JsonResponse {
        $rawPayload = $request->getContent();
        $signature = $request->headers->get('x-paystack-signature');

        if (!$signature) {
            return new JsonResponse(['error' => 'Missing signature'], Response::HTTP_BAD_REQUEST);
        }

        $secretKey = $secretManagerService->getSecret('paystack_test_secret_key');
        $computedSignature = hash_hmac('sha512', $rawPayload, $secretKey);

        if (!hash_equals($computedSignature, $signature)) {
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        $eventData = json_decode($rawPayload, true);
        $event = is_array($eventData) ? ($eventData['event'] ?? null) : null;

        if ('charge.success' === $event) {
            $data = is_array($eventData['data'] ?? null) ? $eventData['data'] : [];
            $reference = $data['reference'] ?? null;

            if ($reference) {
                // The same finalizer the verify and inline-charge paths use: it is
                // idempotent (an already-recorded reference is a no-op) and records the
                // Payment with every field set, confirms the order and sends the email.
                // This is now the self-sufficient confirmation path when the customer
                // never returns to trigger verify-payment.
                $paymentFinalizer->finalize($data, (string) $reference);
            }
        }

        // Always acknowledge with 200 so Paystack does not retry an event we have
        // already processed (or one we intentionally ignore). Signature failures above
        // are the only non-2xx responses.
        return new JsonResponse([
            'status' => 'success',
            'message' => 'Webhook processed successfully',
        ]);
    }
}
