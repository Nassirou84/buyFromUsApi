<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AuthenticationSuccessListener
{

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private NormalizerInterface $objectNormalizer
    ) {
    }

    public function onAuthenticationSuccess(
        AuthenticationSuccessEvent $event
    ) {
        $user = $this->tokenStorage->getToken()->getUser();
        if (!$user instanceof User) {
            return;
        }
        // Save the last login date
        $user->setLastLoginAt(new \DateTime());
        $this->entityManager->persist($user);
        $this->entityManager->flush();


        // Normalize the user object
        $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);
        $event->setData([
            'token' => $event->getData()['token'],
            'user' => $normalizedUser
        ]);
    }
}