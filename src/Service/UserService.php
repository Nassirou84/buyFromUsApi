<?php

namespace App\Service;

use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;

class UserService
{
  public function __construct(
    private UserRepository $userRepository,
    private UserPasswordHasherInterface $passwordHasher,
    private TokenService $tokenService,
    private EntityManagerInterface $entityManager,
  ) {
  }

  public function createUser(User $user): User
  {
    $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
    $user->setPassword($hashedPassword);
    $token = $this->tokenService->generateToken();
    $user->setRegistrationToken($token);
    $this->entityManager->persist($user);
    $this->entityManager->flush();
    return $user;
  }
}