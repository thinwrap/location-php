<?php

declare(strict_types=1);

namespace Thinwrap\Location\Contract;

use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Matrix\MatrixResult;

interface MatrixConnectorInterface
{
    public function getProviderId(): string;

    public function matrix(MatrixOptions $options): MatrixResult;
}
