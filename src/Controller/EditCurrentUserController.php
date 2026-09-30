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
            if (!is_string($data['firstName']) || '' === trim($data['firstName'])) {
                return $this->json(['error' => 'Invalid firstName'], 400);
            }
            $user->setFirstName($data['firstName']);
        }
        if (isset($data['lastName'])) {
            if (!is_string($data['lastName']) || '' === trim($data['lastName'])) {
                return $this->json(['error' => 'Invalid lastName'], 400);
            }
            $user->setLastName($data['lastName']);
        }
        if (isset($data['phone'])) {
            if (!is_string($data['phone'])) {
                return $this->json(['error' => 'Invalid phone'], 400);
            }
            $user->setPhone($data['phone']);
        }
        if (isset($data['addresses'])) {
            if (!is_array($data['addresses'])) {
                return $this->json(['error' => 'Invalid addresses'], 400);
            }
            $user->setAddresses($data['addresses']);
        }
        if (isset($data['twoFactor'])) {
            if (!is_bool($data['twoFactor'])) {
                return $this->json(['error' => 'Invalid twoFactor'], 400);
            }
            $user->setTwoFactor($data['twoFactor']);
        }
        if (isset($data['twoFactorContactMethod'])) {
            if (!in_array($data['twoFactorContactMethod'], ['email', 'sms'], true)) {
                return $this->json(['error' => 'Invalid twoFactorContactMethod'], 400);
            }
            $user->setTwoFactorContactMethod($data['twoFactorContactMethod']);
        }
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json(['user' => $objectNormalizer->normalize($user, null, ['groups' => ['user:login:read', 'user:read']])], 200);
    }
}
