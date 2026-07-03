<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Matrix;

final readonly class MatrixResult
{
    /**
     * @param list<MatrixCell> $cells
     */
    public function __construct(
        public array $cells,
        public mixed $raw = null,
    ) {}
}
