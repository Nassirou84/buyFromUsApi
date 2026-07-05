<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class CurrentlyLoginController extends AbstractController
{
    public function __invoke(
        TokenStorageInterface $tokenStorage,
    ): JsonResponse {
        $user = $tokenStorage->getToken()->getUser();
        return $this->json(['user' => $user], 200);
    }
}