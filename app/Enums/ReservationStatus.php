<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Fulfilled = 'fulfilled';
    case Released = 'released';
}
