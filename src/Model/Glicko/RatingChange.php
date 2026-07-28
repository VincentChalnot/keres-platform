<?php

declare(strict_types=1);

namespace App\Model\Glicko;

/** Before/after pair from one `Glicko2Calculator::rate()` call (06-rating.md sec 9.1). */
final readonly class RatingChange
{
    public function __construct(public Rating $before, public Rating $after)
    {
    }

    /** Exactly what the UI shows; see 06-rating.md sec 8.3. */
    public function delta(): int
    {
        return $this->after->display() - $this->before->display();
    }
}
