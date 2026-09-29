<?php

declare(strict_types=1);

namespace App\MessageHandler;
use App\MessageHandler\MessageHandlerParentClass;

use App\Entity\Banner;
use App\Repository\BannerRepository;
use App\Message\WelcomeMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class WelcomeMessageHandler extends MessageHandlerParentClass
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
    private string $welcomeEmailTemplateId,
    private BannerRepository $bannerRepository,
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

  public function __invoke(WelcomeMessage $message)
  {
    $banner = $this->bannerRepository->findOneBy(['name' => Banner::WELCOME_EMAIL_BANNER_NAME]);
    $bannerData = null;
    if ($banner) {
      $bannerData = [
        'title' => $banner->getTitle(),
        'content' => $banner->getContent(),
        'image_url' => $banner->getImageUrl(),
      ];
    }

    $emailData = [
      'templateId' => (int) $this->welcomeEmailTemplateId,
      'to' => [$message->email => $message->fullName],
      'params' => [
        'full_name' => $message->fullName,
        'STORE_NAME' => $this->websiteName,
        'discount_code' => $message->discountCode,
        'discount_percent' => $message->discountAmount,
        'website_url' => $this->frontendURL,
        'STORE_TAGLINE' => $this->websiteTagline,
        "COMPANY_ADDRESS" => $this->companyAddress,
        'banner' => $bannerData,
        'social_instagram' => $this->socialInstagram,
        'social_facebook' => $this->socialFacebook,
        'social_twitter' => $this->socialTwitter,
        'social_tiktok' => $this->socialTikTok,
      ],
      'subject' => 'Bienvenue à la boutique Flot',
    ];

    $this->brevoMailer->sendEmail(
      $emailData
    );
  }
}