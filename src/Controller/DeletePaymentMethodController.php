<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PaymentMethod;
use App\Service\PaymentMethodService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/payment-methods')]
final class DeletePaymentMethodController extends AbstractController
{
    public function __construct(
        private readonly PaymentMethodService $paymentMethodService,
    ) {
    }

    #[Route('/{id}', name: 'app_payment_method_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(PaymentMethod $paymentMethod): JsonResponse
    {
        // Ownership is enforced here (not just ROLE_USER): a customer may only
        // delete their own payment methods. A foreign id is reported as "not
        // found" so the endpoint does not leak which ids exist.
        if ($paymentMethod->getCustomer() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'payment_method_not_found'], 404);
        }

        $this->paymentMethodService->delete($paymentMethod);

        return $this->json([
            'success' => true,
            'message' => 'payment_method_deleted',
        ]);
    }
}
