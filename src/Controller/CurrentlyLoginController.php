<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class CurrentlyLoginController extends AbstractController
{
    public function __invoke(
        TokenStorageInterface $tokenStorage,
        NormalizerInterface $objectNormalizer,
    ): JsonResponse {
        $user = $tokenStorage->getToken()->getUser();

        return $this->json(['user' => $objectNormalizer->normalize($user, null, ['groups' => ['user:login:read', 'user:read']])], 200);
    }
}
