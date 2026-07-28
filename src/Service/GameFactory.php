<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\Seek;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Service\Game\ClockManager;

final class GameFactory
{
    public function __construct(
        private readonly ClockManager $clockManager,
    ) {
    }

    /** AI/hot-seat games are always casual (D1/D3): unlimited, unrated. */
    public function createAiOrHotseatGame(User $creator, OpponentType $opponentType, ColorPreference $colorPreference): Game
    {
        $creatorColor = $this->resolveColor($colorPreference, ColorPreference::RANDOM);
        $game = new Game($creator, $opponentType, TimeControl::unlimited(), false);
        new GamePlayer($game, $creatorColor, $creator);

        if (OpponentType::AI === $opponentType) {
            new GamePlayer($game, $creatorColor->opposite(), null);
        } else {
            new GamePlayer($game, $creatorColor->opposite(), $creator);
        }

        $this->clockManager->arm($game);

        return $game;
    }

    /**
     * Until Phase 3's seek/challenge flow lands, `$timeControl`/`$rated`
     * default to unlimited/unrated so a manually-created multiplayer game
     * (there is no matchmaking UI yet) behaves like today's casual games.
     *
     * `$arm = false` leaves the clock columns null (no first-move clamp
     * ticking yet) - `CreateTestGameCommand` uses this to let a human open
     * both browser tabs before starting the 30s "did anyone turn up" timer.
     * Call `arm()` once ready. Never expose `$arm` outside dev/test tooling
     * - every real caller (Phase 3 matchmaking) arms immediately.
     */
    public function createMultiplayerGame(
        User $creator,
        User $opponent,
        PieceColor $creatorColor,
        ?TimeControl $timeControl = null,
        bool $rated = false,
        bool $arm = true,
    ): Game {
        $game = new Game($creator, OpponentType::MULTIPLAYER, $timeControl ?? TimeControl::unlimited(), $rated);
        new GamePlayer($game, $creatorColor, $creator);
        new GamePlayer($game, $creatorColor->opposite(), $opponent);

        if ($arm) {
            $this->clockManager->arm($game);
        }

        return $game;
    }

    /**
     * 04-matchmaking.md sec 3.5 step 4 / sec 3.6. `$self` is the acting seek
     * (the one `SeekMatcher` holds `FOR UPDATE`), `$candidate` the one it
     * just locked with `SKIP LOCKED`. Colour, time control and `rated` are
     * resolved from the seeks, never re-asked of the caller: the
     * compatibility predicate already guarantees `rated`/time-control match
     * and colour compatibility (04-matchmaking.md sec 3.2), so this is pure
     * assembly, not another decision point.
     */
    public function createFromSeeks(Seek $self, Seek $candidate): Game
    {
        $selfColor = $this->resolveColor($self->getColorPreference(), $candidate->getColorPreference());

        $game = new Game($self->getUser(), OpponentType::MULTIPLAYER, $self->getTimeControl(), $self->isRated());
        new GamePlayer($game, $selfColor, $self->getUser());
        new GamePlayer($game, $selfColor->opposite(), $candidate->getUser());

        $this->clockManager->arm($game);

        return $game;
    }

    /** Passthrough so `ClockManager::arm()` is still only ever reached through GameFactory. */
    public function arm(Game $game): void
    {
        $this->clockManager->arm($game);
    }

    /**
     * 04-matchmaking.md sec 3.6. The predicate already excludes
     * {WHITE,WHITE} and {BLACK,BLACK}, so a concrete choice on either side
     * always wins over the other's RANDOM; both RANDOM is the only coin
     * flip. Equivalent to, and replaces, the table of five cases: a
     * concrete `$a` is honoured outright, a concrete `$c` flips to its
     * complement, and two RANDOMs decide by chance.
     */
    private function resolveColor(ColorPreference $a, ColorPreference $c): PieceColor
    {
        return match (true) {
            ColorPreference::WHITE === $a => PieceColor::WHITE,
            ColorPreference::BLACK === $a => PieceColor::BLACK,
            ColorPreference::WHITE === $c => PieceColor::BLACK,
            ColorPreference::BLACK === $c => PieceColor::WHITE,
            default => 0 === random_int(0, 1) ? PieceColor::WHITE : PieceColor::BLACK,
        };
    }
}
