<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\UserRepository;
use App\Service\UserService;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class RegisterStateProcessor implements ProcessorInterface
{
    public function __construct(
        private UserService $userService,
        private UserRepository $userRepository,
        private RequestStack $requestStack,
        #[Target('register')]
        private RateLimiterFactoryInterface $registerLimiter,
    ) {
    }

    public function process(
        mixed $user,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): mixed {
        // Throttle registration per client IP to curb automated signups.
        $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->registerLimiter->create($clientIp)->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'too_many_requests',
            );
        }

        $userExist = (bool) $this->userRepository->findOneBy(['email' => $user->getEmail()]);
        if ($userExist) {
            throw new BadRequestHttpException('existing_email');
        }
        $user = $this->userService->createUser($user);

        return $user;
    }
}
