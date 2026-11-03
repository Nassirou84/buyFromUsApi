<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NewDriverMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NewDriverMessageHandler
{
  public function __construct(
    private BrevoEmailService $brevoMailer,
    private string $driverInterfaceUrl,
  ) {
  }

  public function __invoke(NewDriverMessage $message): void
  {
    $this->brevoMailer->sendLocalTemplateEmail(
      $message->email,
      'Bienvenue en tant que chauffeur',
      'email/driver_welcome_email.twig',
      [
        'fullName' => $message->fullName,
        'email' => $message->email,
        'temporaryPassword' => $message->temporaryPassword,
        'driverInterfaceUrl' => $this->driverInterfaceUrl,
      ],
    );
  }
}