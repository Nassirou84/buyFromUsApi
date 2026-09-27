<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Banner;
use App\Repository\BannerRepository;
use App\Message\WelcomeMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class WelcomeMessageHandler
{
  public function __construct(
    private string $welcomeEmailTemplateId,
    private string $frontendURL,
    private string $websiteName,
    private string $websiteTagline,
    private string $companyAddress,
    private BrevoEmailService $brevoEmailService,
    private BannerRepository $bannerRepository
  ) {
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
      ],
      'subject' => 'Bienvenue à la boutique Flot',
    ];

    $this->brevoEmailService->sendEmail(
      $emailData
    );
  }
}