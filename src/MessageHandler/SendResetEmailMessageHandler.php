<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResetEmailMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendResetEmailMessageHandler
{
    public function __construct(
        private BrevoEmailService $brevoMailer,
        private string $websiteName,
        private string $passwordResetTemplateId,
        private string $frontendURL,
        private string $resetPasswordPagePath,
    ) {
    }

    public function __invoke(ResetEmailMessage $message)
    {
        $emailData = [
            'templateId' => (int) $this->passwordResetTemplateId,
            'to' => [$message->email => $message->fullName],
            'params' => [
                'reset_password_link' => $this->frontendURL . $this->resetPasswordPagePath . $message->resetToken,
                'full_name' => $message->fullName,
                'website_name' => $this->websiteName,
            ],
            'subject' => 'Réinitialisation de votre mot de passe',
        ];

        return $this->brevoMailer->sendEmail($emailData);
    }
}
