<?php

namespace App\Service;

use Picqer\Barcode\BarcodeGeneratorSVG;

class ReceiptService
{
  public function generateBarcode(string $reference): string
  {
    $generator = new BarcodeGeneratorSVG();
    $barcode = $generator->getBarcode(
      $reference,
      $generator::TYPE_CODE_128,
      2,
      40
    );

    return 'data:image/svg+xml;base64,' . base64_encode($barcode);
  }
}
