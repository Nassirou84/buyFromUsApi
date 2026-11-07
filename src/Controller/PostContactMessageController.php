<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contact;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;
use function is_array;
use function is_string;

final class PostContactMessageController extends AbstractController
{
    #[Route('/api/contact/', name: 'app_post_contact_message', methods: ['POST'])]
    public function __invoke(
        Request $request,
        ContactRepository $contactRepository,
        EntityManagerInterface $entityManager,
        #[Target('contact')]
        RateLimiterFactoryInterface $contactLimiter,
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'invalid_payload',
            ], 400);
        }

        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
        $fullName = is_string($data['fullName'] ?? null) ? trim($data['fullName']) : '';
        $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';

        $contact = (new Contact())
            ->setReason($reason)
            ->setFullName($fullName)
            ->setEmail($email)
            ->setMessage($message);

        $violations = $validator->validate($contact);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'validation_failed',
                'errors' => $errors,
            ], 422);
        }

        // Reject identical resubmissions from the same email without consuming
        // the per-IP daily allowance.
        if ($contactRepository->existsByEmailAndMessage($email, $message)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'duplicate_message',
            ], 409);
        }

        // Only genuinely new, valid submissions count against the per-IP limit.
        $limiter = $contactLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'too_many_requests',
            ], 429);
        }

        $entityManager->persist($contact);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'contact_message_saved',
        ], 201);
    }
}
