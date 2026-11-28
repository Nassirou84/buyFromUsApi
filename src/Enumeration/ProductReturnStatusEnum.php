<?php

namespace App\Enumeration;

enum ProductReturnStatusEnum: string
{
  case INITIATED = 'initiated';
  case COLLECTED = 'collected';
  case COMPLETED = 'completed';
}