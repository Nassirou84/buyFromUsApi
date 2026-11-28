<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\NewReturnRequestMessage;
use App\Repository\ProductReturnItemRepository;
use App\Repository\ProductReturnRepository;
use App\Service\ProductReturnService;
use App\Service\QrcodeGeneratorService;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

use function in_array;
use function is_array;
use function is_string;

#[Route('api/product-returns')]
final class ProductReturnController extends AbstractController
{
    #[Route('/initiate', name: 'app_product_return', methods: ['POST'])]
    public function initiate(
        Request $request,
        ProductReturnService $productReturnService,
        #[Target('product_return')]
        RateLimiterFactoryInterface $rateLimiter,
        QrcodeGeneratorService $qrcodeGeneratorService,
        MessageBusInterface $messageBus,
    ): JsonResponse {
        $requestData = json_decode($request->getContent(), true);

        if (!$requestData) {
            return new JsonResponse([
                'error' => 'invalid_json',
            ], 400);
        }

        $limiter = $rateLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
            ], 429);
        }

        try {
            $return = $productReturnService->initiateReturn($requestData);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ], 400);
        }

        $items = [];
        foreach ($return->getReturnItems() as $returnItem) {
            $orderItem = $returnItem->getOrderItem();
            $variant = $orderItem?->getVariant();
            $variantImage = (is_array($variant) && !empty($variant['image']) && is_string($variant['image']))
                ? $variant['image']
                : null;

            $items[] = [
                'title' => $orderItem?->getProduct()?->getTitle() ?? '',
                // Prefer the selected variant's image so the thumbnail matches what was bought.
                'image' => $variantImage ?? $orderItem?->getProduct()?->getCoverImage() ?? '',
                'variant' => $orderItem?->getVariantLabel() ?? '',
                'quantity' => (int) $returnItem->getQuantity(),
                'reason' => (string) $returnItem->getReason(),
            ];
        }

        $codeQr = $qrcodeGeneratorService->generateHostedQrCode($return->getUid());
        $messageBus->dispatch(new NewReturnRequestMessage(
            $return->getEmail(),
            $return->getUid(),
            $return->getReturnOrder()->getFullName(),
            $return->getReturnOrder()->getUid(),
            $return->getCreatedAt()->format('d-m-Y H:i'),
            $items,
            $return->getRefundType(),
            $return->getStatus(),
            $codeQr,
            $return->getAdditionalDetails() ?? null,
        ));

        return new JsonResponse([
            'success' => true,
            'message' => 'Product return initiated successfully.',
            'returnUid' => $return->getUid(),
            'status' => $return->getStatus(),
            'email' => $return->getEmail(),
            'refundType' => $return->getRefundtype(),
            'additionalDetails' => $return->getAdditionalDetails(),
            'items' => $items,
        ]);
    }

    /**
     * Returns the whole return (items + their products) normalized for display.
     *
     * Public access: the return `uid` is the unguessable token that identifies it, mirroring
     * the public, rate-limited initiate endpoint used by customers without an account.
     */
    #[Route('/uid/{uid}', name: 'app_product_return_by_uid', methods: ['GET'])]
    public function getByUid(
        string $uid,
        ProductReturnRepository $productReturnRepository,
        SerializerInterface $serializer,
    ): JsonResponse {
        $return = $productReturnRepository->findOneBy(['uid' => $uid]);

        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_DRIVER', $user->getRoles(), true) && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        if (!$return) {
            return new JsonResponse(['error' => 'return_not_found'], Response::HTTP_NOT_FOUND);
        }

        $data = $serializer->serialize($return, 'json', ['groups' => ['product_return:read']]);

        return new JsonResponse($data, Response::HTTP_OK, [], true);
    }

    #[Route('/uid/{uid}/item/{itemId}/condition', name: 'app_product_return_update_item_status', methods: ['PATCH'])]
    public function updateReturnItemStatus(
        string $uid,
        int $itemId,
        Request $request,
        ProductReturnRepository $productReturnRepository,
        ProductReturnItemRepository $productReturnItemRepository,
        ProductReturnService $productReturnService,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $return = $productReturnRepository->findOneBy(['uid' => $uid]);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        if (!$return) {
            return new JsonResponse(['error' => 'return_not_found'], Response::HTTP_NOT_FOUND);
        }

        $item = $productReturnItemRepository->findOneBy(['id' => $itemId, 'productReturn' => $return]);
        if (!$item) {
            return new JsonResponse(['error' => 'item_not_found'], Response::HTTP_NOT_FOUND);
        }

        $condition = $data['condition'] ?? null;
        if (null === $condition) {
            return new JsonResponse(['error' => 'condition_required'], Response::HTTP_BAD_REQUEST);
        }

        $item = $productReturnService->updateReturnItemStatus($item, ['condition' => $condition, 'controls' => $data['controls'] ?? []], $user);

        return new JsonResponse(['success' => true, 'item' => $item]);
    }

    #[Route('/uid/{uid}/add-note', name: 'app_product_return_add_note', methods: ['POST'])]
    public function addReturnNote(
        string $uid,
        Request $request,
        ProductReturnRepository $productReturnRepository,
        ProductReturnService $productReturnService,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $return = $productReturnRepository->findOneBy(['uid' => $uid]);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        if (!$return) {
            return new JsonResponse(['error' => 'return_not_found'], Response::HTTP_NOT_FOUND);
        }

        $note = $data['note'] ?? null;
        if (null === $note) {
            return new JsonResponse(['error' => 'note_required'], Response::HTTP_BAD_REQUEST);
        }

        $productReturnService->addReturnNote($return, $note);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/item/{itemId}/approve', name: 'app_product_return_approve_item', methods: ['POST'])]
    public function approveReturnItem(
        int $itemId,
        ProductReturnItemRepository $productReturnItemRepository,
        ProductReturnService $productReturnService,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $item = $productReturnItemRepository->find($itemId);
        if (!$item) {
            return new JsonResponse(['error' => 'item_not_found'], Response::HTTP_NOT_FOUND);
        }

        $productReturnService->approveReturnItem($item, $user);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/item/{itemId}/reject', name: 'app_product_return_reject_item', methods: ['POST'])]
    public function rejectReturnItem(
        int $itemId,
        ProductReturnItemRepository $productReturnItemRepository,
        ProductReturnService $productReturnService,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $item = $productReturnItemRepository->find($itemId);
        if (!$item) {
            return new JsonResponse(['error' => 'item_not_found'], Response::HTTP_NOT_FOUND);
        }

        $productReturnService->rejectReturnItem($item, $user);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/{uid}/complete', name: 'app_product_return_complete_return', methods: ['POST'])]
    public function refundAndCloseReturn(
        string $uid,
        ProductReturnRepository $productReturnRepository,
        ProductReturnService $productReturnService,
        SerializerInterface $serializer,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $return = $productReturnRepository->findOneBy(['uid' => $uid]);
        if (!$return) {
            return new JsonResponse(['error' => 'return_not_found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $productReturn = $productReturnService->completeReturn($return, $user);
        } catch (LogicException $e) {
            // Not allowed in the current state; nothing was refunded.
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (RuntimeException $e) {
            // refund_rejected / refund_outcome_unknown: the attempt is recorded on the Refund.
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $data = $serializer->serialize($productReturn, 'json', ['groups' => ['product_return:read']]);

        return new JsonResponse([
            'success' => true,
            'productReturn' => json_decode($data, true),
        ]);
    }
}