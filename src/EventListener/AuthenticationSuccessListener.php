<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class AuthenticationSuccessListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private NormalizerInterface $objectNormalizer,
    ) {
    }

    public function onAuthenticationSuccess(
        AuthenticationSuccessEvent $event,
    ): void {
        $user = $this->tokenStorage->getToken()->getUser();
        if (!$user instanceof User) {
            return;
        }
        // Save the last login date
        $user->setLastLoginAt(new DateTime());
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Normalize the user object
        $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);
        $event->setData([
            'token' => $event->getData()['token'],
            'user' => $normalizedUser,
        ]);
    }
}
