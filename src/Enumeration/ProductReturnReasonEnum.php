<?php

namespace App\Enumeration;

enum ProductReturnReasonEnum: string
{
  case CHANGED = 'rr.reason_changed';
  case WRONG = 'rr.reason_wrong';
  case DEFECTIVE = 'rr.reason_defective';
  case NOT_DESCRIBED = 'rr.reason_not_described';
  case LATE = 'rr.reason_late';
  case OTHER = 'rr.reason_other';

  /**
   * Human-readable (French) description for this reason.
   */
  public function label(): string
  {
    return match ($this) {
      self::CHANGED => "J'ai changé d'avis",
      self::WRONG => 'Mauvais article reçu',
      self::DEFECTIVE => 'Défectueux ou endommagé',
      self::NOT_DESCRIBED => 'Non conforme à la description',
      self::LATE => 'Arrivé trop tard',
      self::OTHER => 'Autre',
    };
  }

  /**
   * Resolves a stored reason code to its description, falling back to the raw
   * value (capitalised) when it is not one of the known reasons.
   */
  public static function labelFor(?string $reason): string
  {
    if (null === $reason || '' === $reason) {
      return '';
    }

    return self::tryFrom($reason)?->label() ?? ucfirst($reason);
  }
}
