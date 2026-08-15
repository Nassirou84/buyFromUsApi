<?php

namespace App\Service;

use App\Repository\BasketRepository;
use App\Repository\OrderRepository;
use App\Repository\ShoppingRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class UniqUidGenerator
{
  public function __construct(
    private SluggerInterface $slugger,
    private OrderRepository $orderRepository,
    private ShoppingRequestRepository $shoppingRequestRepository,
    private BasketRepository $basketRepository,
    private EntityManagerInterface $entityManager
  ) {
  }

  public function generateUniqueUid(string $classEntity): string
  {
    $repository = $this->entityManager->getRepository($classEntity);
    $uid = '';
    $prefix = '';
    if ($classEntity === 'App\Entity\Basket') {
      $prefix = 'B-';
    } elseif ($classEntity === 'App\Entity\ShoppingRequest') {
      $prefix = 'R-';
    } elseif ($classEntity === 'App\Entity\Order') {
      $prefix = 'O-';
    } else if ($classEntity === 'App\Entity\User') {
      $prefix = 'U-';
    }


    do {
      $uid = $this->slugger->slug($prefix . substr(uniqid(), -5));
    } while ($repository->findOneBy(['uid' => $uid]));
    return $uid;
  }

  public function generateUniqueTokenForUser(): string
  {
    $token = '';
    $userRepository = $this->entityManager->getRepository('App\Entity\User');

    do {
      $token = substr(uniqid(), -10);
    } while ($userRepository->findOneBy(['passwordResetToken' => $token]));
    return $token;
  }
}