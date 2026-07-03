<?php

declare(strict_types=1);

namespace Thinwrap\Location\Contract;

use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\Isochrone\IsochroneResult;

interface IsochroneConnectorInterface
{
    public function getProviderId(): string;

    public function isochrone(IsochroneOptions $options): IsochroneResult;
}
