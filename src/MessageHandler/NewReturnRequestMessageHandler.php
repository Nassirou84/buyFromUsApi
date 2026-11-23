<?php

namespace App\MessageHandler;

use App\Entity\ProductReturn;
use App\Enumeration\ProductReturnReasonEnum;
use App\Message\NewReturnRequestMessage;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NewReturnRequestMessageHandler extends MessageHandlerParentClass
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
    private string $newReturnRequestTemplate
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

  public function __invoke(NewReturnRequestMessage $message): void
  {
    // One structured line per returned item for the Brevo template loop.
    $items = array_map(
      static fn (array $item): array => [
        'TITLE' => ucfirst($item['title']),
        'IMAGE' => $item['image'],
        'VARIANT' => $item['variant'],
        'QUANTITY' => $item['quantity'],
        'REASON' => ProductReturnReasonEnum::labelFor($item['reason']),
      ],
      $message->items
    );

    $emailData = [
      'templateId' => (int) $this->newReturnRequestTemplate,
      'to' => [$message->email => $message->fullName],
      'params' => [
        'FULL_NAME' => ucfirst($message->fullName),
        'STORE_NAME' => $this->websiteName,
        'RETURN_REF' => $message->returnRef,
        'CREATED_AT' => $message->createdAt,
        'ITEMS' => $items,
        'ORDER_REF' => $message->orderRef,
        'EMAIL' => $message->email,
        'STATUS' => $message->status,
        'REFUND_TYPE' => $message->refundType == ProductReturn::STORE_CREDIT ? 'Avoir d\'achat' : 'Remboursement',
        'ADDITIONAL_DETAILS' => $message->additionalDetails ?? '',
        'CODE_QR' => $message->codeQr ?? '',
        'HELP_URL' => $this->frontendURL . $this->supportUrl,
        'website_url' => $this->frontendURL,
        'STORE_TAGLINE' => $this->websiteTagline,
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
      'subject' => 'Nouvelle demande de retour',
    ];
    $this->brevoMailer->sendEmail(
      $emailData
    );
  }
}