<?php

declare(strict_types=1);

namespace App\Service\Rating;

use App\Entity\Game;
use App\Entity\User;
use App\Entity\UserRating;
use App\Model\Glicko\Rating;
use App\Model\MultiplayerLimits;
use App\Model\PieceColor;
use App\Model\SpeedCategory;
use App\Repository\UserRatingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Doctrine side of Glicko-2 (06-rating.md sec 9.3). Touches
 * `game_player` and `user_rating` only - never `Game` (sec 3.5), so a
 * rating update never fires the `#[ORM\Version]` UPDATE that would
 * interfere with `GameEngine::applyMove()`'s hand-rolled lock path.
 */
final readonly class RatingUpdater
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRatingRepository $userRatings,
        private Glicko2Calculator $calculator,
    ) {
    }

    /**
     * Current inflation-adjusted rating. Never persists, never inserts.
     * Returns `Rating::initial()` when no row exists.
     *
     * `$category` is deliberately NOT nullable (sec 4.3): `UNLIMITED` has no
     * pool, and its callers must say so with a literal placeholder rather
     * than by passing null here.
     */
    public function currentRating(User $user, SpeedCategory $category, ?\DateTimeImmutable $at = null): Rating
    {
        $row = $this->userRatings->findOneFor($user, $category);

        $raw = null === $row ? Rating::initial() : new Rating(
            rating: $row->getRating(),
            deviation: $row->getDeviation(),
            volatility: $row->getVolatility(),
            gamesPlayed: $row->getGamesPlayed(),
            lastRatedAt: $row->getLastRatedAt(),
        );

        return $this->calculator->inflate($raw, $at ?? new \DateTimeImmutable());
    }

    /**
     * All five pools for one user in a single query (06-rating.md sec 5.3:
     * profile rendering is a high-volume read). Missing categories come
     * back as an inflated `Rating::initial()`, exactly like `currentRating()`.
     *
     * @return array<string, Rating> keyed by lowercase SpeedCategory case name
     */
    public function currentRatingsForAllCategories(User $user, ?\DateTimeImmutable $at = null): array
    {
        $now = $at ?? new \DateTimeImmutable();
        $rows = [];

        foreach ($this->userRatings->findAllFor($user) as $row) {
            $rows[$row->getCategory()->value] = $row;
        }

        $ratings = [];

        foreach (SpeedCategory::cases() as $category) {
            $row = $rows[$category->value] ?? null;
            $raw = null === $row ? Rating::initial() : $this->rawRating($row);
            $ratings[strtolower($category->name)] = $this->calculator->inflate($raw, $now);
        }

        return $ratings;
    }

    /**
     * Invariant 4. MUST run inside the transaction that writes
     * `gameOverAt` - every current call site (`GameLifecycleManager`'s four
     * methods) already holds a `PESSIMISTIC_WRITE` lock on the `game` row
     * there. No-op when the game is not rated (sec 6) or already rated.
     */
    public function applyForFinishedGame(Game $game): void
    {
        $gameOverAt = $game->getGameOverAt();

        if (null === $gameOverAt) {
            return; // 1
        }

        $whitePlayer = $game->getPlayer(PieceColor::WHITE);
        $blackPlayer = $game->getPlayer(PieceColor::BLACK);

        if (null !== $whitePlayer->getRatingAfter() || null !== $blackPlayer->getRatingAfter()) {
            return; // 2 - idempotence guard
        }

        if (!$game->isRatedOutcome()) {
            return; // 3
        }

        $category = $game->getSpeedCategory() ?? throw new \LogicException('isRatedOutcome() is true, so speedCategory cannot be null.');

        $whiteUser = $whitePlayer->getUser() ?? throw new \LogicException('isRatedOutcome() is true, so both users are non-null.');
        $blackUser = $blackPlayer->getUser() ?? throw new \LogicException('isRatedOutcome() is true, so both users are non-null.');

        // 5: sort by user_id ascending (sec 5.3/5.4 global lock order suffix).
        [$firstUser, $firstPlayer, $secondUser, $secondPlayer] = $whiteUser->getId()->toRfc4122() <= $blackUser->getId()->toRfc4122()
            ? [$whiteUser, $whitePlayer, $blackUser, $blackPlayer]
            : [$blackUser, $blackPlayer, $whiteUser, $whitePlayer];

        // 6: materialise both rows (never overwrites an existing one), then lock both, in that order.
        $this->materialiseRow($firstUser, $category);
        $this->materialiseRow($secondUser, $category);

        $locked = $this->userRatings->lockForUpdate([$firstUser, $secondUser], $category);
        $rowByUserId = [];

        foreach ($locked as $row) {
            $rowByUserId[$row->getUser()->getId()->toRfc4122()] = $row;
        }

        $firstRow = $rowByUserId[$firstUser->getId()->toRfc4122()] ?? throw new \LogicException('user_rating row missing immediately after materialisation.');
        $secondRow = $rowByUserId[$secondUser->getId()->toRfc4122()] ?? throw new \LogicException('user_rating row missing immediately after materialisation.');

        // 7: inflate both to gameOverAt, before either update touches anything (sec 2.5B: simultaneity is mandatory).
        $firstPreGame = $this->calculator->inflate($this->rawRating($firstRow), $gameOverAt);
        $secondPreGame = $this->calculator->inflate($this->rawRating($secondRow), $gameOverAt);

        $whitePreGame = $whitePlayer === $firstPlayer ? $firstPreGame : $secondPreGame;
        $blackPreGame = $blackPlayer === $firstPlayer ? $firstPreGame : $secondPreGame;

        // 8
        $whiteScore = $game->isDraw() ? 0.5 : ($game->isWhiteWins() ? 1.0 : 0.0);
        $blackScore = 1.0 - $whiteScore;

        // 9: both calls consume only the step-7 pre-game snapshots.
        $whiteChange = $this->calculator->rateSingle($whitePreGame, $blackPreGame, $whiteScore, $gameOverAt);
        $blackChange = $this->calculator->rateSingle($blackPreGame, $whitePreGame, $blackScore, $gameOverAt);

        // 10
        $whitePlayer->writeRatingSnapshot(
            $whitePreGame->display(),
            (int) round($whitePreGame->deviation),
            $whiteChange->after->display(),
            $whitePreGame->isProvisional(),
        );
        $blackPlayer->writeRatingSnapshot(
            $blackPreGame->display(),
            (int) round($blackPreGame->deviation),
            $blackChange->after->display(),
            $blackPreGame->isProvisional(),
        );

        // 11
        $firstChange = $whitePlayer === $firstPlayer ? $whiteChange : $blackChange;
        $secondChange = $whitePlayer === $secondPlayer ? $whiteChange : $blackChange;
        $firstRow->apply($firstChange->after->rating, $firstChange->after->deviation, $firstChange->after->volatility, $gameOverAt);
        $secondRow->apply($secondChange->after->rating, $secondChange->after->deviation, $secondChange->after->volatility, $gameOverAt);
    }

    private function materialiseRow(User $user, SpeedCategory $category): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO user_rating (user_id, category, rating, deviation, volatility, games_played, last_rated_at)
                VALUES (:userId, :category, :rating, :deviation, :volatility, 0, NULL)
                ON CONFLICT (user_id, category) DO NOTHING
                SQL,
            [
                'userId' => $user->getId()->toRfc4122(),
                'category' => $category->value,
                'rating' => MultiplayerLimits::GLICKO_DEFAULT_RATING,
                'deviation' => MultiplayerLimits::GLICKO_DEFAULT_RD,
                'volatility' => MultiplayerLimits::GLICKO_DEFAULT_VOLATILITY,
            ],
        );
    }

    private function rawRating(UserRating $row): Rating
    {
        return new Rating(
            rating: $row->getRating(),
            deviation: $row->getDeviation(),
            volatility: $row->getVolatility(),
            gamesPlayed: $row->getGamesPlayed(),
            lastRatedAt: $row->getLastRatedAt(),
        );
    }
}
