<?php

declare(strict_types=1);

namespace Thinwrap\Location\Config;

final readonly class HereConfig
{
    public function __construct(
        public string $apiKey,
    ) {}
}
