<?php

declare(strict_types=1);

namespace Thinwrap\Location\Util;

use Thinwrap\Location\DTO\LatLng;

final class Coordinate
{
    /**
     * Join an array of coordinates as "lng,lat" pairs with a separator.
     *
     * @param list<LatLng> $coords
     */
    public static function joinLngLat(array $coords, string $separator): string
    {
        return implode($separator, array_map(
            static fn(LatLng $c): string => $c->toLngLatString(),
            $coords,
        ));
    }

    /**
     * Join an array of coordinates as "lat,lng" pairs with a separator.
     *
     * @param list<LatLng> $coords
     */
    public static function joinLatLng(array $coords, string $separator): string
    {
        return implode($separator, array_map(
            static fn(LatLng $c): string => $c->toLatLngString(),
            $coords,
        ));
    }
}
