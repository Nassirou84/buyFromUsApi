<?php

declare(strict_types=1);

namespace App\Controller;
use App\Service\BrevoEmailService;
use App\Service\RequestThrottleService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AddToNewsletterController extends AbstractController
{
    #[Route('/api/add-to-newsletter/', name: 'app_add_to_newsletter', methods: ['POST'])]
    public function index(
        Request $request,
        BrevoEmailService $brevoEmailService,
        RequestThrottleService $throttle,
    ): JsonResponse {
        // Public endpoint that triggers a Brevo contact creation: throttle per
        // client IP so it cannot be used to spam signups.
        if (!$throttle->allow('newsletter:' . $request->getClientIp(), 5, 60)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'too_many_requests',
            ], 429);
        }

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        $firstName = $data['firstName'] ?? null;

        if (!$email) {
            return new JsonResponse([
                'success' => false,
                'message' => 'email_is_required',
            ], 400);
        }

        $success = $brevoEmailService->addContact($email, $firstName);

        return new JsonResponse([
            'success' => $success,
            'message' => $success ? 'contact_added_successfully' : 'contact_add_failed',
        ]);
    }
}