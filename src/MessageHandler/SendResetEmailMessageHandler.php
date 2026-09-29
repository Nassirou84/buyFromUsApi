<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResetEmailMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\MessageHandler\MessageHandlerParentClass;

#[AsMessageHandler]
class SendResetEmailMessageHandler extends MessageHandlerParentClass
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
        private string $passwordResetTemplateId,
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

    public function __invoke(ResetEmailMessage $message)
    {
        $emailData = [
            'templateId' => (int) $this->passwordResetTemplateId,
            'to' => [$message->email => $message->fullName],
            'params' => [
                'EXPIRY_MINUTES' => $message->expiryMinutes,
                'EMAIL' => $message->email,
                'RESET_URL' => $this->frontendURL . $this->resetPasswordPagePath . $message->resetToken,
                'FIRST_NAME' => $message->fullName,
                'STORE_NAME' => $this->websiteName,
                'STORE_TAGLINE' => $this->websiteTagline,
                'COMPANY_ADDRESS' => $this->companyAddress,
                'SUPPORT_EMAIL' => $this->supportEmail,
                'BRAND_NAME' => $this->websiteName,
                'social_instagram' => $this->socialInstagram ?? '',
                'social_facebook' => $this->socialFacebook ?? '',
                'social_twitter' => $this->socialTwitter ?? '',
                'social_tiktok' => $this->socialTiktok ?? ''
            ],
            'subject' => 'Réinitialisation de votre mot de passe',
        ];

        return $this->brevoMailer->sendEmail($emailData);
    }
}