<?php

declare(strict_types=1);

namespace Thinwrap\Location\Enum;

enum IsochroneType: string
{
    case Time = 'time';
    case Distance = 'distance';
}
