<?php

declare(strict_types=1);

namespace App\Model;

enum PieceColor: int
{
    case WHITE = 0;
    case BLACK = 1;

    public function opposite(): self
    {
        return self::WHITE === $this ? self::BLACK : self::WHITE;
    }
}
