<?php

declare(strict_types=1);

namespace App\Model;

enum PieceColor: int
{
    public function opposite(): self
    {
        return self::WHITE === $this ? self::BLACK : self::WHITE;
    }
    case WHITE = 0;
    case BLACK = 1;
}
