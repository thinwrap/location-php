<?php

declare(strict_types=1);

namespace Thinwrap\Location\Enum;

enum LocationProviderId: string
{
    case Google = 'google';
    case Mapbox = 'mapbox';
    case Here = 'here';
    case Esri = 'esri';
    case Osrm = 'osrm';
    case TomTom = 'tomtom';
}
