<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NewAdminCreatedMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NewAdminCreatedMessageHandler extends MessageHandlerParentClass
{
    public function __construct(
        BrevoEmailService $brevoMailer,
        string $frontendURL,
        string $websiteName,
        string $websiteTagline,
        string $companyAddress,
        string $supportEmail,
        string $socialInstagram,
        string $socialFacebook,
        string $socialTwitter,
        string $socialTikTok,
        string $currency,
        private string $resetPasswordPagePath,
    ) {
        parent::__construct(
            $brevoMailer,
            $frontendURL,
            $websiteName,
            $websiteTagline,
            $companyAddress,
            $supportEmail,
            $socialInstagram,
            $socialFacebook,
            $socialTwitter,
            $socialTikTok,
            $currency,
        );
    }

    public function __invoke(NewAdminCreatedMessage $message): void
    {
        $passwordChangeLink = $this->frontendURL . $this->resetPasswordPagePath . $message->resetToken;

        $this->brevoMailer->sendTextEmail(
            $message->adminEmail,
            'Nouvel administrateur créé',
            'Bonjour ' . $message->fullName . ', votre compte administrateur a été créé. Veuillez définir votre mot de passe en utilisant le lien suivant (valable ' . $message->expiryMinutes . ' minutes) : ' . $passwordChangeLink,
        );
    }
}
