<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\UserRepository;
use App\Service\UserService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class RegisterStateProcessor implements ProcessorInterface
{
    public function __construct(
        private UserService $userService,
        private UserRepository $userRepository,
    ) {
    }

    public function process(
        mixed $user,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): mixed {
        $userExist = (bool) $this->userRepository->findOneBy(['email' => $user->getEmail()]);
        if ($userExist) {
            throw new BadRequestHttpException('existing_email');
        }
        $user = $this->userService->createUser($user);

        return $user;
    }
}