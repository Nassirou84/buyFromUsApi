<?php

namespace App\Service;

use App\Repository\UserRepository;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

class TokenService
{
    public function __construct(
        private TokenGeneratorInterface $tokenGenerator,
        private UserRepository $userRepository,
    ) {
    }

    public function generateToken(): string
    {
        $userUsersExist = true;
        do {
            $token = $this->tokenGenerator->generateToken();
            $userUsersExist = $this->userRepository->findOneBy(['registrationToken' => $token]);
        } while ($userUsersExist);

        return $token;
    }

    public function generateResetPasswordToken(): string
    {
        $userUsersExist = true;
        do {
            $token = $this->tokenGenerator->generateToken();
            $userUsersExist = $this->userRepository->findOneBy(['resetPasswordToken' => $token]);
        } while ($userUsersExist);

        return $token;
    }

    public function generateCode(): string
    {
        return rand(100000, 999999);
    }
}