<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\BrevoEmailService;

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
}