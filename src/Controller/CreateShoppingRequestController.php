<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShoppingRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class CreateShoppingRequestController extends AbstractController
{
    public function __invoke(
        Request $request,
        ShoppingRequestService $shoppingRequestService,
    ): JsonResponse {
        $files = $request->files;
        if (
            !$request->request->has('title') || !$request->request->has('description') || !$request->request->has('quantity') || !$request->request->has('fullName') || !$request->request->has('email') || !$request->request->has('phone')
            || !$request->request->has('address')
            || !$request->request->has('preferredContact')
        ) {
            throw new \Exception('Veuillez remplir tous les champs requis.');
        }

        if (count($files) === 0) {
            throw new \Exception('Veuillez envoyer au moins une image.');
        }
        $shoppingRequest = $shoppingRequestService->createShoppingRequest($request);

        return $this->json($shoppingRequest, 201, [], ['groups' => ['shopping_request:read']]);
    }
}