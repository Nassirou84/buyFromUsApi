<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;


final class TrackOrderController extends AbstractController
{
    #[Route('/api/track-order', name: 'track_order', methods: ['GET'])]
    public function track(
        Request $request,
        OrderRepository $orderRepository
    ): JsonResponse {
        $orderId = $request->query->get('uid');
        $email = $request->query->get('email');

        $order = $orderRepository->findOneBy(['uid' => $orderId]);

        if (!$order) {
            return $this->json([
                'error' => 'Order not found'
            ], 404);
        }

        if ($order->getStatus() === 'cancelled') {
            return $this->json([
                'error' => 'Order has been cancelled'
            ], 400);
        }


        if ($order->getEmail() !== $email) {
            return $this->json([
                'error' => 'Email does not match'
            ], 400);
        }

        return $this->json($order, 200);
    }
}