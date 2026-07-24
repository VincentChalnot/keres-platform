<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Entity\User;

/**
 * DQL `NEW` projection target for UserListAction. sidus/datagrid-bundle's
 * Column::renderValue() strictly requires an `object`, not the plain array
 * Doctrine would otherwise produce for a query mixing a full entity select
 * with extra scalar subselects — projecting into a real object via
 * `SELECT NEW App\Model\Admin\UserListRow(...)` keeps every row an object
 * the datagrid can resolve property paths against ('user.email',
 * 'gamesCount', ...).
 */
final readonly class UserListRow
{
    public ?\DateTimeImmutable $lastMoveAt;

    public function __construct(
        public User $user,
        public int $gamesCount,
        public int $winCount,
        public int $loseCount,
        public int $drawCount,
        ?string $lastMoveAt,
    ) {
        // Scalar subquery results inside a DQL NEW expression bypass
        // Doctrine's normal field-type hydration and arrive as raw driver
        // strings, unlike a mapped datetime_immutable field.
        $this->lastMoveAt = null !== $lastMoveAt ? new \DateTimeImmutable($lastMoveAt) : null;
    }
}
