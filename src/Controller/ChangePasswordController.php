<?php

namespace App\Controller;
use App\Repository\UserRepository;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/change_password')]
final class ChangePasswordController extends AbstractController
{
    #[Route('/{email}/request', name: 'app_change_password_request', methods: ['POST'])]
    public function request(
        string $email,
        UserRepository $userRepository,
        UserService $userService
    ): JsonResponse {
        $email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Adresse e-mail invalide.'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Cet email n\'est pas enregistré.'], 404);
        }

        $userService->generateChangePasswordToken($user);
        return $this->json(['success' => true, 'message' => 'Token de réinitialisation du mot de passe généré avec succès.'], 200);
    }

    #[Route('/{token}/validate', name: 'app_change_password_validate', methods: ['GET'])]
    public function validate(
        string $token,
        UserService $userService
    ): JsonResponse {
        $isValid = $userService->validateChangePasswordToken($token);
        if (!$isValid) {
            return $this->json(['success' => false, 'error' => 'Token invalide ou expiré.'], 400);
        }
        return $this->json(['success' => true, 'message' => 'Token valide.'], 200);
    }

    #[Route('/change', name: 'app_change_password', methods: ['POST'])]
    public function changePassword(
        EntityManagerInterface $entityManager,
        UserService $userService,
        UserRepository $userRepository,
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $newPassword = $data['newPassword'] ?? null;

        if (!$token || !$newPassword) {
            return $this->json(['success' => false, 'error' => 'Token et nouveau mot de passe sont requis.'], 400);
        }
        $isValid = $userService->validateChangePasswordToken($token);
        if (!$isValid) {
            return $this->json(['success' => false, 'error' => 'Token invalide ou expiré.'], 400);
        }
        $user = $userRepository->findOneBy(['passwordResetToken' => $token]);
        $user->setPasswordResetToken(null);
        $user->setPasswordResetTokenExpiredAt(null);
        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json(['success' => true, 'message' => 'Mot de passe changé avec succès.'], 200);
    }
}