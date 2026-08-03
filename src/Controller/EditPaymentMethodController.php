<?php

namespace App\Controller;

use App\Entity\PaymentMethod;
use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class EditPaymentMethodController extends AbstractController
{
    public function __invoke(
        PaymentMethod $paymentMethod,
        EntityManagerInterface $entityManagerInterface,
        TokenStorageInterface $tokenStorageInterface,
        Request $request,
        PaymentMethodRepository $paymentMethodRepository
    ): JsonResponse {

        if (!$tokenStorageInterface->getToken()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }
        $user = $tokenStorageInterface->getToken()->getUser();
        if ($paymentMethod->getUser() !== $user) {
            return $this->json(['message' => 'Vous n\'êtes pas autorisé à modifier ce mode de paiement.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['isDefault'])) {
            $paymentMethod->setIsDefault($data['isDefault']);
            $existingDefaultPaymentMethod = $paymentMethodRepository->findOneBy([
                'user' => $user,
                'isDefault' => true,
            ]);
            if ($existingDefaultPaymentMethod && $existingDefaultPaymentMethod !== $paymentMethod) {
                $existingDefaultPaymentMethod->setIsDefault(false);
                $entityManagerInterface->persist($existingDefaultPaymentMethod);
            }
        }

        if (isset($data['cardHolderName'])) {
            $paymentMethod->setCardHolderName($data['cardHolderName']);
        }

        if (isset($data['expiryDate'])) {
            $paymentMethod->setExpiry($data['expiryDate']);
        }

        $entityManagerInterface->persist($paymentMethod);
        $entityManagerInterface->flush();

        return $this->json(['message' => 'Payment method updated successfully.'], 200);
    }
}