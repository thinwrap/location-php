<?php

declare(strict_types=1);

namespace Thinwrap\Location\Config;

final readonly class OsrmConfig
{
    public function __construct(
        public string $baseUrl,
    ) {}
}
