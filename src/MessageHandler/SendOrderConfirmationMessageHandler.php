<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderConfirmationMessage;
use App\MessageHandler\MessageHandlerParentClass;
use App\Service\BrevoEmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendOrderConfirmationMessageHandler extends MessageHandlerParentClass
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
    private string $orderConfirmationTemplateId
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

  public function __invoke(OrderConfirmationMessage $message)
  {
    $emailData = [
      'templateId' => (int) $this->orderConfirmationTemplateId,
      'to' => [$message->email => $message->fullName],
      'params' => [
        'FIRSTNAME' => $message->fullName,
        'STORE_NAME' => $this->websiteName,
        'DELIVERY_WINDOW' => $message->estimatedDelivery,
        'ORDER_NUMBER' => strtoupper($message->orderId),
        'CURRENCY' => $this->currency,
        'ITEMS' => $message->items,
        'SUBTOTAL' => $message->subTotal,
        'SHIPPING_FEE' => $message->deliveryFee,
        'TAX' => $message->taxAmount,
        'TOTAL' => $message->totalAmount,
        'DISCOUNT' => $message->discountAmount,
        'PAYMENT_METHOD' => $message->paymentMethod,
        'SHIPPING_NAME' => $message->shippingAddress['fullName'] ?? '',
        'SHIPPING_ADDRESS' => $message->shippingAddress['street'] ?? '',
        'SHIPPING_ADDRESS_SUITE' => $message->shippingAddress['suite'] ?? '',
        'SHIPPING_CITY' => $message->shippingAddress['city'] ?? '',
        'SHIPPING_STATE' => $message->shippingAddress['state'] ?? '',
        'SHIPPING_PHONE' => $message->shippingAddress['phone'] ?? '',
        'SHIPPING_TYPE' => $message->shippingType,
        'PAYMENT_TYPE' => $message->paymentMethod,
        'PAYMENT_LAST4' => $message->paymentLast4 ? 'se terminant par ' . $message->paymentLast4 : '',
        'PAYMENT_STATUS' => $message->paymentStatus,
        'STORE_TAGLINE' => $this->websiteTagline,
        'COMPANY_ADDRESS' => $this->companyAddress,
        'INVOICE_URL' => $this->frontendURL . '/receipt/?reference=' . $message->orderId . '&accessToken=' . $message->accessToken,
        'ACCOUNT_ORDER_URL' => $this->frontendURL . '/user/orders/',
        'TRACKING_URL' => $this->frontendURL . '/tracking/?order=' . $message->orderId . '&email=' . $message->email,
        'social_instagram' => $this->socialInstagram,
        'social_facebook' => $this->socialFacebook,
        'social_twitter' => $this->socialTwitter,
        'social_tiktok' => $this->socialTikTok,
      ],
      'subject' => 'Confirmation de votre commande',
    ];

    return $this->brevoMailer->sendEmail($emailData);
  }
}