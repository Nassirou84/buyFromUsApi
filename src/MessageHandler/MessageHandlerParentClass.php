<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\BrevoEmailService;
use RuntimeException;

abstract class MessageHandlerParentClass
{
  public function __construct(
    protected BrevoEmailService $brevoMailer,
    protected string $frontendURL,
    protected string $websiteName,
    protected string $websiteTagline,
    protected string $companyAddress,
    protected string $supportEmail,
    protected string $socialInstagram,
    protected string $socialFacebook,
    protected string $socialTwitter,
    protected string $socialTikTok,
    protected string $currency
  ) {
  }

  /**
   * Sends a Brevo template email and throws when Brevo rejects it, so Messenger
   * retries the message and finally moves it to the failed transport.
   */
  protected function sendTemplatedEmail(array $emailData): array
  {
    $result = $this->brevoMailer->sendEmail($emailData);

    if (!$result['success']) {
      throw new RuntimeException('Brevo email failed: ' . $result['error']);
    }

    return $result;
  }
}
