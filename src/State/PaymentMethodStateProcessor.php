<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PaymentMethod;
use App\Repository\PaymentMethodRepository;
use App\Service\CardValidator;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class PaymentMethodStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private Security $security,
        private CardValidator $cardValidator,
        private PaymentMethodRepository $paymentMethodRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof PaymentMethod) {
            return $data;
        }

        $user = $this->security->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new BadRequestHttpException('Utilisateur non authentifié.');
        }

        if (PaymentMethod::METHOD_CARD === $data->getMethod()) {
            $cardData = [
                'cardNumber' => $data->getCardNumber(),
                'expiry' => $data->getExpiry(),
                'cvv' => $data->getCvv(),
                'cardHolderName' => $data->getCardHolderName(),
            ];

            $errors = $this->cardValidator->validateCard($cardData);

            if (!empty($errors)) {
                throw new BadRequestHttpException(json_encode($errors));
            }
        } elseif (PaymentMethod::METHOD_MOBILE_PAYMENT === $data->getMethod()) {
            if (empty($data->getMobilePaymentNumber())) {
                throw new BadRequestHttpException('Le numéro de paiement mobile est requis pour la méthode de paiement mobile.');
            }
            if (!preg_match('/^\+225\d{10}$/', $data->getMobilePaymentNumber())) {
                throw new BadRequestHttpException('Le numéro de paiement mobile doit commencer par +225 et contenir exactement 10 chiffres après l\'indicatif du pays.');
            }
            if (empty($data->getMobileProvider())) {
                throw new BadRequestHttpException('Le fournisseur de paiement mobile est requis pour la méthode de paiement mobile.');
            }
        } else {
            throw new BadRequestHttpException('Méthode de paiement invalide.');
        }

        if (PaymentMethod::METHOD_CARD === $data->getMethod()) {
            $existingPaymentMethod = $this->paymentMethodRepository->findOneBy([
                'user' => $user,
                'lastFourDigits' => substr($data->getCardNumber(), -4),
                'cardHolderName' => $data->getCardHolderName(),
                'expiry' => $data->getExpiry(),
                'method' => PaymentMethod::METHOD_CARD,
            ]);

            if ($existingPaymentMethod) {
                throw new BadRequestHttpException('Cette carte est déjà enregistrée pour cet utilisateur.');
            }
        }

        if (PaymentMethod::METHOD_MOBILE_PAYMENT === $data->getMethod()) {
            $existingPaymentMethod = $this->paymentMethodRepository->findOneBy([
                'user' => $user,
                'lastFourDigits' => substr($data->getMobilePaymentNumber(), -4),
                'method' => PaymentMethod::METHOD_MOBILE_PAYMENT,
            ]);

            if ($existingPaymentMethod) {
                throw new BadRequestHttpException('Ce numéro de paiement mobile est déjà enregistré pour cet utilisateur.');
            }
        }

        if ($data->isDefault()) {
            $existingDefaultPaymentMethod = $this->paymentMethodRepository->findOneBy([
                'user' => $user,
                'isDefault' => true,
            ]);

            if ($existingDefaultPaymentMethod) {
                $existingDefaultPaymentMethod->setIsDefault(false);
                $entityManager = $this->managerRegistry->getManager();
                $entityManager->persist($existingDefaultPaymentMethod);
            }
        }

        $data->setUser($user);
        $data->setCreatedAt(new DateTimeImmutable());

        $entityManager = $this->managerRegistry->getManager();
        $entityManager->persist($data);
        $entityManager->flush();

        return $data;
    }
}
