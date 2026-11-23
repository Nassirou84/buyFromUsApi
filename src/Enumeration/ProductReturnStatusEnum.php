<?php

namespace App\Enumeration;

enum ProductReturnStatusEnum: string
{
  case INITIATED = 'initiated';
  case COLLECTED = 'collected';
  case INSPECTED = 'inspected';
  case APPROVED = 'approved';
  case COMPLETED = 'completed';
  case REJECTED = 'rejected';
}