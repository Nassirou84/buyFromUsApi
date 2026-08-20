<?php

declare(strict_types=1);

namespace App\Service;

use DateTime;

use function strlen;

class CardValidator
{
    /**
     * Validates card using Luhn algorithm.
     */
    public function validateLuhn(string $cardNumber): bool
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            return false;
        }

        $sum = 0;
        $alternate = false;

        for ($i = strlen($cardNumber) - 1; $i >= 0; --$i) {
            $n = (int) $cardNumber[$i];
            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n = ($n % 10) + 1;
                }
            }
            $sum += $n;
            $alternate = !$alternate;
        }

        return 0 === $sum % 10;
    }

    /**
     * Validates expiry date (not expired).
     */
    public function validateExpiry(string $expiry): bool
    {
        if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiry, $matches)) {
            return false;
        }

        $month = (int) $matches[1];
        $year = (int) $matches[2] + 2000; // Convert YY to YYYY

        $now = new DateTime();
        $expiryDate = new DateTime("$year-$month-01");
        $expiryDate->modify('+1 month last day');

        return $expiryDate >= $now;
    }

    /**
     * Validates CVV length based on card brand.
     */
    public function validateCvv(string $cvv, string $cardBrand): bool
    {
        $cvv = preg_replace('/\D/', '', $cvv);

        // AMEX uses 4-digit CVV
        $expectedLength = ('amex' === $cardBrand) ? 4 : 3;

        return strlen($cvv) === $expectedLength && ctype_digit($cvv);
    }

    /**
     * Comprehensive card validation.
     */
    public function validateCard(array $cardData): array
    {
        $errors = [];

        // Validate card number
        if (!isset($cardData['cardNumber']) || !$this->validateLuhn($cardData['cardNumber'])) {
            $errors['cardNumber'] = 'Carte invalide';
        }

        // Validate expiry
        if (!isset($cardData['expiry']) || !$this->validateExpiry($cardData['expiry'])) {
            $errors['expiry'] = 'Date d\'expiration invalide';
        }

        // Validate CVV
        if (isset($cardData['cardNumber']) && isset($cardData['cvv'])) {
            $brand = $this->detectCardBrand($cardData['cardNumber']);
            if (!$this->validateCvv($cardData['cvv'], $brand)) {
                $errors['cvv'] = 'CVV invalide';
            }
        }

        // Validate cardholder name
        if (!isset($cardData['cardHolderName']) || strlen(trim($cardData['cardHolderName'])) < 2) {
            $errors['cardHolderName'] = 'Le nom du titulaire de la carte est requis';
        }

        return $errors;
    }

    private function detectCardBrand(string $cardNumber): string
    {
        $patterns = [
            'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
            'mastercard' => '/^5[1-5][0-9]{14}$|^2(?:2[2-9][0-9]{2}|[3-6][0-9]{3}|7[0-1][0-9]{2}|720[0-9]{2})[0-9]{12}$/',
            'amex' => '/^3[47][0-9]{13}$/',
            'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
            'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
            'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        ];

        foreach ($patterns as $brand => $pattern) {
            if (preg_match($pattern, $cardNumber)) {
                return $brand;
            }
        }

        return 'unknown';
    }
}
