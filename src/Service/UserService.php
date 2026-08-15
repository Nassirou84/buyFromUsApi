<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailQueueService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
  public function __construct(
    private UserRepository $userRepository,
    private UserPasswordHasherInterface $passwordHasher,
    private TokenService $tokenService,
    private EntityManagerInterface $entityManager,
    private BasketService $basketService,
    private UniqUidGenerator $uniqUidGenerator,
    private EmailQueueService $emailQueueService
  ) {
  }

  public function createUser(User $user): User
  {
    $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
    $user->setPassword($hashedPassword);
    $token = $this->tokenService->generateToken();
    $user->setRegistrationToken($token);
    $user->setRegistrationTokenCreatedAt(new \DateTime());
    $this->entityManager->persist($user);
    $this->entityManager->flush();
    $this->basketService->createBasketForUser($user);
    return $user;
  }

  public function generateChangePasswordToken(User $user): string
  {
    try {
      $dateTimeNow = new \DateTime();
      $token = $this->uniqUidGenerator->generateUniqueTokenForUser();
      $user->setPasswordResetToken($token);
      $user->setPasswordResetTokenExpiredAt($dateTimeNow->modify('+1 hour'));
      $this->entityManager->persist($user);
      $this->entityManager->flush();
      $this->emailQueueService->sendPasswordResetEmail($user->getEmail(), $user->getFullName(), "/auth/reset-password/?me=$token");
      return $token;
    } catch (\Exception $e) {
      throw new \Exception('Erreur lors de la génération du token de réinitialisation du mot de passe : ' . $e->getMessage());
    }
  }

  public function validateChangePasswordToken(string $token): bool
  {
    $user = $this->userRepository->findOneBy(['passwordResetToken' => $token]);
    if (!$user) {
      return false;
    }
    $now = new \DateTime();
    if ($user->getPasswordResetTokenExpiredAt() < $now) {
      return false;
    }
    return true;
  }
}