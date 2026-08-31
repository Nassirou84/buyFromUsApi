<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GuestBasketService;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Cookie;

#[Route('api/guest', name: 'app_guest_action')]
final class GuestActionController extends AbstractController
{
    #[Route('/basket', name: 'app_guest_action_create_or_retrieve', methods: ['GET', 'OPTIONS'])]
    public function createOrRetrieve(
        Request $request,
        GuestBasketService $guestBasketService,
        NormalizerInterface $objectNormalizer,
    ): Response {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $basketUid = $request->cookies->get('guest_basket_uid') ?? null;

        $basket = $guestBasketService->createOrRetrieveBasket($basketUid);

        $cookie = $this->createGuestBasketCookie($basket->getUid());

        $normalizedBasket = $objectNormalizer->normalize($basket, null, [
            'groups' => ['basket:read']
        ]);

        $response = new JsonResponse($normalizedBasket);

        $response->headers->setCookie($cookie);
        return $response;
    }

    private function createGuestBasketCookie(string $basketUid): Cookie
    {
        return Cookie::create('guest_basket_uid')
            ->withValue($basketUid)
            ->withExpires(new \DateTimeImmutable('+1 year'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite(Cookie::SAMESITE_NONE);
    }
}