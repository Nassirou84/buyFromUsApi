<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\RequestThrottleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TrackOrderController extends AbstractController
{
    #[Route('/api/track-order', name: 'track_order', methods: ['GET'])]
    public function track(
        Request $request,
        OrderRepository $orderRepository,
        RequestThrottleService $throttle,
    ): JsonResponse {
        // Public, unauthenticated endpoint: throttle per client IP so it cannot
        // be used to brute-force uid/email pairs.
        if (!$throttle->allow('track-order:' . $request->getClientIp(), 10, 60)) {
            return $this->json(['error' => 'too_many_requests'], 429);
        }

        $orderId = $request->query->get('uid');
        $email = $request->query->get('email');

        $order = $orderRepository->findOneBy(['uid' => $orderId]);

        // Return the same response whether the order does not exist or the email
        // does not match, so the endpoint cannot confirm which order uids exist.
        if (!$order || $order->getEmail() !== $email) {
            return $this->json(['error' => 'order_not_found'], 404);
        }

        if ('cancelled' === $order->getStatus()) {
            return $this->json(['error' => 'order_has_been_cancelled'], 400);
        }

        // Serialize with the public order group only. Without an explicit group
        // the serializer emits every field, which leaked the receipt accessToken
        // and the customer's email/payments to anyone with a uid + email.
        return $this->json($order, 200, [], ['groups' => ['order:read', 'order:read:tracking']]);
    }
}
