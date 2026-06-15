<?php

namespace App\State;

use App\Entity\User;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\UserRepository;
use App\Service\UserService;

class RegisterStateProcessor implements ProcessorInterface
{

    public function __construct(
        private UserService $userService,
        private UserRepository $userRepository
    ) {
    }

    public function process(
        mixed $user,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): User {
        $userExist = (bool) $this->userRepository->findOneBy(['email' => $user->getEmail()]);
        if ($userExist) {
            throw new \Exception('Cet email est déjà utilisé.');
        }
        $user = $this->userService->createUser($user);
        return $user;
    }
}