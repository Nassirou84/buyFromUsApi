<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TwoFactorCodeMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendTwoFactorCodeMessageHandler extends MessageHandlerParentClass
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
        private string $twoFactorCodeTemplate,
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

    public function __invoke(TwoFactorCodeMessage $message)
    {
        $emailData = [
            'templateId' => (int) $this->twoFactorCodeTemplate,
            'to' => [$message->email => $message->fullName],
            'params' => [
                'CODE' => $message->authCode,
                'FIRST_NAME' => $message->fullName,
                'EMAIL' => $message->email,
                'ACTION_TYPE' => 'Requête de connexion',
                'TIMESTAMP' => (new \DateTime())->format('d-m-Y H:i:s'),
                'DEVICE_INFO' => $message->deviceInfo ?? 'Inconnu',
                'SUPPORT_EMAIL' => $this->supportEmail,
                'STORE_NAME' => $this->websiteName,
                'EXPIRY_MINUTES' => $message->expiryMinutes,
                'STORE_TAGLINE' => $this->websiteTagline,
                'COMPANY_ADDRESS' => $this->companyAddress,
                'SECURITY_URL' => $this->frontendURL . '/faqs/?topic=security',
                'CHANGE_PASSWORD_URL' => $this->frontendURL . '/user/security',
                'social_instagram' => $this->socialInstagram ?? '',
                'social_facebook' => $this->socialFacebook ?? '',
                'social_twitter' => $this->socialTwitter ?? '',
                'social_tiktok' => $this->socialTikTok ?? ''
            ],
            'subject' => 'Votre code de vérification à deux facteurs',
        ];

        return $this->brevoMailer->sendEmail($emailData);
    }
}