<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\UserService;

#[Route('api/admin', name: 'app_admin')]
final class AdminController extends AbstractController
{
    #[Route('/create', name: 'app_admin_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserService $userService
    ): Response {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'invalid_data'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'not_authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array(User::ROLE_SUPER_ADMIN, $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'not_super_admin'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $userService->createAdminUser($data);

        return new JsonResponse(['success' => true, 'user' => $user->getId()]);
    }

    #[Route('/{id}/toggle-access', name: 'app_admin_toggle_access', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function toggleAccess(
        int $id,
        UserService $userService
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'not_authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true) === false) {
            return new JsonResponse(['error' => 'not_super_admin'], JsonResponse::HTTP_FORBIDDEN);
        }

        $admin = $userService->findUserById($id);
        if (!$admin || !in_array(User::ROLE_ADMIN, $admin->getRoles(), true)) {
            return new JsonResponse(['error' => 'admin_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($admin->getUserIdentifier() === $user->getUserIdentifier()) {
            return new JsonResponse(['error' => 'cannot_toggle_self'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $admin = $userService->toggleAdminAccess($admin);

        return new JsonResponse(['success' => true, 'id' => $admin->getId(), 'isGrantedAccess' => $admin->isGrantedAccess()]);
    }

    #[Route('/{id}/send-reset-link', name: 'app_admin_send_reset_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendResetLink(
        int $id,
        UserService $userService
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'not_authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true) === false) {
            return new JsonResponse(['error' => 'not_super_admin'], JsonResponse::HTTP_FORBIDDEN);
        }

        $admin = $userService->findUserById($id);
        if (!$admin || !in_array(User::ROLE_ADMIN, $admin->getRoles(), true)) {
            return new JsonResponse(['error' => 'admin_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $userService->sendAdminResetLink($admin);

        return new JsonResponse(['success' => true, 'id' => $admin->getId()]);
    }

    #[Route('/list', name: 'app_admin_list', methods: ['GET'])]
    public function list(
        UserService $userService,
        Request $request
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'not_authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array(User::ROLE_SUPER_ADMIN, $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'not_super_admin'], JsonResponse::HTTP_FORBIDDEN);
        }

        $page = (int) $request->query->get('page', 1);
        $name = $request->query->get('name', '');

        $users = $userService->getAdminUsers($page, $name);

        return new JsonResponse([
            'success' => true,
            'users' => $users['users'],
            'all' => $users['all']
        ]);
    }
}