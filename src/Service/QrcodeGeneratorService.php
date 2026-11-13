<?php

namespace App\Service;

use Skies\QRcodeBundle\Generator\Generator;

final class QrcodeGeneratorService
{
  public function __construct(
    private Generator $generator,
    private GcsUploaderService $gcsUploaderService
  ) {
  }

  /**
   * Generates a QR code as a base64 PNG data URI.
   *
   * PNG (not SVG) is used because email clients do not render SVG. Note that
   * most clients (Gmail, Outlook) also strip "data:" image URIs, so for
   * anything shown in an email use generateHostedQrCode() instead.
   */
  public function generateQrCode(string $text): string
  {
    // getBarcodePNG() already returns a base64-encoded PNG string.
    $base64Png = $this->generator->generate([
      'code' => $text,
      'type' => 'qrcode',
      'format' => 'png',
      'width' => 10,
      'height' => 10,
      'color' => [0, 0, 0],
    ]);

    return 'data:image/png;base64,' . $base64Png;
  }

  /**
   * Generates a PNG QR code, uploads it to Google Cloud Storage and returns a
   * public https URL that email clients can render.
   */
  public function generateHostedQrCode(string $text): string
  {
    return $this->gcsUploaderService->uploadDataUri(
      $this->generateQrCode($text),
      'return-qr-' . $text
    );
  }
}
