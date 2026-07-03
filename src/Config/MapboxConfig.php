<?php

declare(strict_types=1);

namespace Thinwrap\Location\Config;

final readonly class MapboxConfig
{
    public function __construct(
        public string $accessToken,
    ) {}
}
