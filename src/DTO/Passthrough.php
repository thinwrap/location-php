<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO;

final readonly class Passthrough
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $query
     */
    public function __construct(
        public ?array $body = null,
        public ?array $headers = null,
        public ?array $query = null,
    ) {}
}
