<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\PaymentMethod;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

class PaymentMethodDataProvider implements ProviderInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->security->getUser();

        if (!$user) {
            return null;
        }

        $repository = $this->managerRegistry->getRepository(PaymentMethod::class);

        // For single card
        if (isset($uriVariables['id'])) {
            return $repository->findOneBy([
                'id' => $uriVariables['id'],
                'customer' => $user,
            ]);
        }

        // For collection
        return $repository->findBy(['customer' => $user], ['isDefault' => 'DESC', 'createdAt' => 'DESC']);
    }
}
