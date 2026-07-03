<?php

declare(strict_types=1);

namespace Thinwrap\Location\Contract;

use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;

interface RoutingConnectorInterface
{
    public function getProviderId(): string;

    public function route(RoutingOptions $options): RoutingResult;
}
