<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Repository\ProductRepository;
use App\Service\OrderService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class PlaceOrderController extends AbstractController
{
    public function __invoke(
        TokenStorageInterface $tokenStorageInterface,
        Request $request,
        ProductRepository $productRepository,
        OrderService $orderService,
        EntityManagerInterface $entityManagerInterface,
    ): JsonResponse {
        $user = null;
        if (!$tokenStorageInterface->getToken()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Utilisateur non authentifié.'], 401);
        }
        $user = $tokenStorageInterface->getToken()->getUser();

        $currentCountry = $_ENV['APP_CURRENT_COUNTRY'] ?? '';

        $data = json_decode($request->getContent(), true);
        try {
            $cart = $data['cart'] ?? [];
            $order = new Order();
            $order->setFirstName($data['firstName'] ?? '');
            $order->setLastName($data['lastName'] ?? '');
            if ($user instanceof \App\Entity\User) {
                $order->setCustomer($user);
            }
            $order->setStreet($data['street'] ?? '');
            $order->setCity($data['city'] ?? '');
            $order->setState($data['state'] ?? '');
            $order->setOrderPrice($data['checkoutTotal'] ?? 0.0);
            $order->setCountry($currentCountry);
            $order->setEstimatedDeliveryAt((new DateTime())->modify('+3 weeks'));
            $order = $orderService->createOrder($order);

            foreach ($cart as $item) {
                $orderItem = new OrderItem();
                $orderItem->setQuantity($item['quantity'] ?? 0);
                $orderItem->setUnitPrice($item['product']['actualPrice'] ?? 0.0);
                $orderItem->setProduct($productRepository->find($item['product']['id'] ?? null));
                $orderItem->setCurrentOrder($order);

                $entityManagerInterface->persist($orderItem);
                $order->addProduct($orderItem);
            }

            $entityManagerInterface->flush();

            return new JsonResponse(['status' => 'success', 'order' => $order]);
        } catch (Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
