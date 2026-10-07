<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Entity\Game;

/**
 * DQL `NEW` projection target for GameListAction (same rationale as
 * UserListRow): wraps the Game with the scalar subselects the datagrid
 * cannot resolve from the entity, so listing a page of games costs one
 * query instead of one lazy-load per player/user/move collection.
 *
 * A null user id/name means the engine plays that colour (GamePlayer::isEngine()).
 */
final readonly class GameListRow
{
    public function __construct(
        public Game $game,
        public int $movesCount,
        public ?string $whiteUserId,
        public ?string $whiteUsername,
        public ?string $blackUserId,
        public ?string $blackUsername,
    ) {
    }
}
