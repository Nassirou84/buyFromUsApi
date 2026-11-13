<?php

namespace App\Controller;
use App\Message\NewReturnRequestMessage;
use App\Repository\ProductReturnRepository;
use App\Service\ProductReturnService;
use App\Service\QrcodeGeneratorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

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
        MessageBusInterface $messageBus
    ): JsonResponse {

        $requestData = json_decode($request->getContent(), true);

        if (!$requestData) {
            return new JsonResponse([
                'error' => 'invalid_json'
            ], 400);
        }

        $limiter = $rateLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'error' => 'rate_limit_exceeded'
            ], 429);
        }

        try {
            $return = $productReturnService->initiateReturn($requestData);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
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
            $return->getAdditionalDetails() ?? null
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

        if (!$return) {
            return new JsonResponse(['error' => 'return_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $data = $serializer->serialize($return, 'json', ['groups' => ['product_return:read']]);

        return new JsonResponse($data, JsonResponse::HTTP_OK, [], true);
    }
}