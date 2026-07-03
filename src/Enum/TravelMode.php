<?php

declare(strict_types=1);

namespace Thinwrap\Location\Enum;

enum TravelMode: string
{
    case Driving = 'driving';
    case Walking = 'walking';
    case Cycling = 'cycling';
}
