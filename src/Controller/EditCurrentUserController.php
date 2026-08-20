<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class EditCurrentUserController extends AbstractController
{
    public function __invoke(
        Request $request,
        TokenStorageInterface $tokenStorage,
        EntityManagerInterface $entityManager,
        NormalizerInterface $objectNormalizer,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $user = $tokenStorage->getToken()->getUser();

        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => 'User not found'], 404);
        }

        if (isset($data['firstName'])) {
            $user->setFirstName($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastName($data['lastName']);
        }
        if (isset($data['phone'])) {
            $user->setPhone($data['phone']);
        }
        if (isset($data['addresses'])) {
            $user->setAddresses($data['addresses']);
        }
        if (isset($data['twoFactor'])) {
            $user->setTwoFactor($data['twoFactor']);
        }
        if (isset($data['twoFactorContactMethod'])) {
            $user->setTwoFactorContactMethod($data['twoFactorContactMethod']);
        }
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json(['user' => $objectNormalizer->normalize($user, null, ['groups' => ['user:login:read', 'user:read']])], 200);
    }
}
