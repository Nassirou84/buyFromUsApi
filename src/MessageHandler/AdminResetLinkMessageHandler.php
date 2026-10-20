<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AdminResetLinkMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AdminResetLinkMessageHandler extends MessageHandlerParentClass
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
        private string $adminFrontendURL,
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

    public function __invoke(AdminResetLinkMessage $message): void
    {
        $this->brevoMailer->sendLocalTemplateEmail(
            $message->email,
            'Réinitialisation de votre mot de passe',
            'email/admin_reset_link.html.twig',
            [
                'fullName' => $message->fullName,
                'resetUrl' => $this->adminFrontendURL . $this->resetPasswordPagePath . $message->resetToken,
            ],
        );
    }
}
