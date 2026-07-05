<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

final class EditCurrentUserController extends AbstractController
{
  public function __invoke(
    Request $request,
    TokenStorageInterface $tokenStorage,
    EntityManagerInterface $entityManager,
  ): JsonResponse {
    $data = json_decode($request->getContent(), true);
    if (!$data) {
      return $this->json(['error' => 'Invalid JSON'], 400);
    }

    $user = $tokenStorage->getToken()->getUser();
    if (isset($data['firstName'])) {
      $user->setFirstName($data['firstName']);
    }
    if (isset($data['lastName'])) {
      $user->setLastName($data['lastName']);
    }
    if (isset($data['phone'])) {
      $user->setPhone($data['phone']);
    }
    if (isset($data['street'])) {
      $user->setStreet($data['street']);
    }
    if (isset($data['city'])) {
      $user->setCity($data['city']);
    }
    if (isset($data['state'])) {
      $user->setState($data['state']);
    }
    $entityManager->persist($user);
    $entityManager->flush();
    return $this->json(['user' => $user], 200);
  }
}