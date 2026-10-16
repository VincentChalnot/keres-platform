<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Repository\SeekRepository;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\GameFactory;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Matchmaking\SeekMatcher;
use App\Service\Notification\NotificationCenter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The "Rematch" button of the game-over modal: a new game with the parameters of a finished one and the colours
 * swapped, linked to it by `Game::$parentGame` (unique: a game has at most one rematch, whoever asks first).
 *
 * AI and hot-seat games have no one to ask, so the rematch is created at once (under a lock on the parent row).
 * A multiplayer rematch needs both players: the first click posts a rematch seek (a seek addressed to the
 * opponent alone, `Seek::$rematchOfGame`, shown only to its owner in the lobby) and notifies the opponent; the
 * opponent's click - the acceptance - pairs the two seeks through `SeekMatcher`, which creates the game. Returns
 * the game as soon as it exists, null while the opponent has not answered yet.
 */
final readonly class RematchService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepository $gameRepository,
        private SeekRepository $seekRepository,
        private SeekCreationService $seekCreationService,
        private SeekMatcher $seekMatcher,
        private GameFactory $gameFactory,
        private BotGameManager $botGameManager,
        private NotificationCenter $notificationCenter,
        private AnalyticsRecorder $analyticsRecorder,
        private ClockInterface $clock,
    ) {
    }

    /** The caller has checked that `$parent` is over and that `$user` took part in it. */
    public function request(User $user, Game $parent): ?Game
    {
        $existing = $this->gameRepository->findRematchOf($parent);

        if (null !== $existing) {
            return $existing;
        }

        return OpponentType::MULTIPLAYER === $parent->getOpponentType()
            ? $this->requestMultiplayer($user, $parent)
            : $this->startSolo($user, $parent);
    }

    private function startSolo(User $user, Game $parent): Game
    {
        $created = false;

        $game = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($user, $parent, &$created): Game {
            // The parent row is the lock: two concurrent clicks cannot both create a rematch.
            $em->find(Game::class, $parent->getId(), LockMode::PESSIMISTIC_WRITE);
            $existing = $this->gameRepository->findRematchOf($parent);

            if (null !== $existing) {
                return $existing;
            }

            // One bot game at a time (D5): a casual AI rematch never opens a second one, the running game is resumed.
            if (OpponentType::AI === $parent->getOpponentType() && null !== ($running = $this->botGameManager->findCurrent($user))) {
                return $running;
            }

            $game = $this->gameFactory->createAiOrHotseatGame(
                $user,
                $parent->getOpponentType(),
                self::swapped($parent->getCreatorColor()),
                $parent->getAiLevel(),
                $parent->isLiveEvaluationEnabled(),
                $parent,
            );
            $em->persist($game);
            $em->flush();
            $created = true;

            return $game;
        });

        if ($created) {
            $this->analyticsRecorder->gameStarted($user, $game, $parent->getOpponentType(), $parent->getAiLevel());
        }

        return $game;
    }

    private function requestMultiplayer(User $user, Game $parent): ?Game
    {
        $opponent = $parent->getOpponentOf($user) ?? throw new \LogicException('A multiplayer game has two human players.');
        $now = $this->clock->now();

        $mine = $this->seekCreationService->insertOrReplaceSeek(
            $user,
            $parent->getTimeControl(),
            $parent->isRated(),
            self::swapped($parent->getColorsForUser($user)[0]),
            false,
            null,
            null,
            rematchOf: $parent,
        );

        // The opponent asked first (or both clicked at once and this click is the later one): this click accepts.
        $theirs = $this->seekRepository->findOpenRematchSeek($parent, $opponent, $now);

        if (null !== $theirs) {
            return $this->seekMatcher->attemptPair((int) $mine->seek->getId(), $theirs->getUuid());
        }

        if (!$mine->deduped) {
            $this->notificationCenter->rematchOffered($opponent, $user, $parent);
        }

        return null;
    }

    private static function swapped(PieceColor $color): ColorPreference
    {
        return PieceColor::WHITE === $color ? ColorPreference::BLACK : ColorPreference::WHITE;
    }
}
