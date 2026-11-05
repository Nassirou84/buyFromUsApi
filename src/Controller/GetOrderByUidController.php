<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final class GetOrderByUidController extends AbstractController
{
    #[Route('/api/orders/uid/{uid}', name: 'app_order_by_uid', methods: ['GET'])]
    public function __invoke(
        string $uid,
        OrderRepository $orderRepository,
        SerializerInterface $serializer,
    ): JsonResponse {
        $order = $orderRepository->findOneBy(['uid' => $uid]);

        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $isAdmin = $this->isGranted(User::ROLE_ADMIN);
        $isOwner = $user instanceof User && $order->getCustomer() === $user;
        if (!$isAdmin && !$isOwner) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $groups = ['order:read', 'order:read:details'];
        if ($isAdmin) {
            $groups[] = 'order:read:admin';
        }

        $json = $serializer->serialize($order, 'json', [
            'groups' => $groups,
        ]);

        return new JsonResponse($json, JsonResponse::HTTP_OK, [], true);
    }
}
