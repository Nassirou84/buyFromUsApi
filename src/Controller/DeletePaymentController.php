<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class DeletePaymentController extends AbstractController
{
    public function __invoke(
        PaymentMethod $paymentMethod,
        EntityManagerInterface $entityManagerInterface,
        TokenStorageInterface $tokenStorageInterface,
    ): JsonResponse {
        if (!$tokenStorageInterface->getToken()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $user = $tokenStorageInterface->getToken()->getUser();
        if ($paymentMethod->getUser() !== $user) {
            return $this->json(['message' => 'Vous n\'êtes pas autorisé à supprimer ce mode de paiement.'], 403);
        }

        $entityManagerInterface->remove($paymentMethod);
        $entityManagerInterface->flush();

        return $this->json(['message' => 'Mode de paiement supprimé avec succès.'], 200);
    }
}
