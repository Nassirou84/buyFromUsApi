<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\MessageHandler\MessageHandlerParentClass;
use App\Message\NewShoppingRequestMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NewShoppingRequestHandler extends MessageHandlerParentClass
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
    string $supportUrl,
    string $faqs,
    string $privacyPolicy,
    string $generalTerms,
    string $contactUs,
    string $aboutUs,
    string $cokiePolicy,
    private string $newShoppingRequestTemplate,
    private string $statusPath,
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
      $supportUrl,
      $faqs,
      $privacyPolicy,
      $generalTerms,
      $contactUs,
      $aboutUs,
      $cokiePolicy
    );
  }
  public function __invoke(NewShoppingRequestMessage $message)
  {
    $emailData = [
      'templateId' => (int) $this->newShoppingRequestTemplate,
      'to' => [$message->email => $message->fullName],
      'params' => [
        'FULL_NAME' => ucfirst($message->fullName),
        'STORE_NAME' => $this->websiteName,
        'REQUEST_REF' => $message->uid,
        'CREATED_AT' => $message->createdAt,
        'TITLE' => ucfirst($message->title),
        'DESCRIPTION' => ucfirst($message->description),
        'QUANTITY' => $message->quantity,
        'PREFERRED_CONTACT' => $message->preferredContact === 'email' ? 'Email' : 'Téléphone',
        'EMAIL' => $message->email,
        'PHONE' => $message->phoneNumber,
        'ADDRESS' => $message->address,
        'HELP_URL' => $this->frontendURL . $this->supportUrl,
        'website_url' => $this->frontendURL,
        'STORE_TAGLINE' => $this->websiteTagline,
        'REQUEST_URL' => $this->frontendURL . $this->statusPath . '/?request=' . $message->uid . '&referrer=' . $message->accessToken,
        "COMPANY_ADDRESS" => $this->companyAddress,
        'social_instagram' => $this->socialInstagram,
        'social_facebook' => $this->socialFacebook,
        'social_twitter' => $this->socialTwitter,
        'social_tiktok' => $this->socialTikTok,
        'FAQS_URL' => $this->frontendURL . $this->faqs,
        'PRIVACY_URL' => $this->frontendURL . $this->privacyPolicy,
        'TERMS_URL' => $this->frontendURL . $this->generalTerms,
        'CONTACT_URL' => $this->frontendURL . $this->contactUs,
        'ABOUT_US_URL' => $this->frontendURL . $this->aboutUs,
        'COKIE_POLICY_URL' => $this->frontendURL . $this->cokiePolicy,
      ],
      'subject' => 'Nouvelle demande d\'achat',
    ];
    $this->brevoMailer->sendEmail(
      $emailData
    );
  }
}