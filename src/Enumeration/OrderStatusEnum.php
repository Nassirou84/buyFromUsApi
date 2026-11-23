<?php

namespace App\Enumeration;

enum OrderStatusEnum: string
{
  case PENDING = 'pending';
  case PLACED = 'placed';
  case PROCESSED = 'processed';
  case IN_TRANSIT = 'in_transit';
  case ARRIVED_IN_FACILITY = 'arrived_in_facility';
  case OUT_FOR_DELIVERY = 'out_for_delivery';
  case DELIVERED = 'delivered';
  case CANCELLED = 'cancelled';
}