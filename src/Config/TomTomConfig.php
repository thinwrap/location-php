<?php

declare(strict_types=1);

namespace Thinwrap\Location\Config;

final readonly class TomTomConfig
{
    public function __construct(
        public string $apiKey,
    ) {}
}
