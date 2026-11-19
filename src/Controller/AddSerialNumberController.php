<?php

namespace App\Controller;

use App\Repository\OrderItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final class AddSerialNumberController extends AbstractController
{
    public function __invoke(
        Request $request,
        OrderItemRepository $orderItemRepository,
        EntityManagerInterface $entityManagerInterface,
        NormalizerInterface $normalizer,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $orderItemId = $data['orderItemId'] ?? null;
        $productSerialNumber = $data['productSerialNumber'] ?? null;

        $user = $this->getUser();
        if (!$user) {
            return $this->json([
                'error' => 'user_not_authenticated'
            ], 401);
        }

        if (!$orderItemId || !$productSerialNumber) {
            return $this->json([
                'error' => 'missing_orderItemId_or_productSerialNumber'
            ], 400);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json([
                'error' => 'user_not_authorized'
            ], 403);
        }

        $orderItem = $orderItemRepository->find($orderItemId);
        if ($orderItem) {
            $orderItem->setProductSerialNumber($productSerialNumber);
            $entityManagerInterface->persist($orderItem);
            $entityManagerInterface->flush();
        }

        $normalizedOrderItem = $orderItem ? $normalizer->normalize($orderItem, null, ['groups' => ['order:read']]) : null;

        return $this->json([
            $normalizedOrderItem
        ], 200);
    }
}