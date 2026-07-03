<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Matrix;

final readonly class MatrixCell
{
    public function __construct(
        public int $originIndex,
        public int $destinationIndex,
        public float $distanceMeters,
        public float $durationSeconds,
    ) {}
}
