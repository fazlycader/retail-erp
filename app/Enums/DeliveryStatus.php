<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Scheduled = 'scheduled';
    case Assigned = 'assigned';
    case Loaded = 'loaded';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Rescheduled = 'rescheduled';
}
