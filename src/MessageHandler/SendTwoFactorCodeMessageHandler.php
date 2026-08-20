<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TwoFactorCodeMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendTwoFactorCodeMessageHandler
{
    public function __construct(
        private BrevoEmailService $brevoMailer,
        private string $twoFactorCodeTemplate,
    ) {
    }

    public function __invoke(TwoFactorCodeMessage $message)
    {
        $emailData = [
            'templateId' => (int) $this->twoFactorCodeTemplate,
            'to' => [$message->email => $message->fullName],
            'params' => [
                'code' => $message->authCode,
                'full_name' => $message->fullName,
            ],
            'subject' => 'Votre code de vérification à deux facteurs',
        ];

        return $this->brevoMailer->sendEmail($emailData);
    }
}
