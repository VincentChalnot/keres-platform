<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\Game;
use App\Entity\User;
use App\Form\LocalGameType;
use App\Model\OpponentType;
use App\Repository\GameRepository;
use App\Service\Announcement\AnnouncementProvider;
use App\Service\BotTournament\BotAccounts;
use App\Service\Game\BotGameManager;
use App\Service\Ladder\NextBot;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /` - the home page, the one entry point of the app (template `actions/index.html.twig`, registered in
 * `templating.yaml`).
 *
 * Anonymous visitors get a single hero (play now as a guest, sign in, learn). Signed-in players get, top to bottom:
 * "Continue" (their running bot game, multiplayer games waiting for their move), "Play" (the bot, online, a friend),
 * "Your games" (the other in-progress multiplayer games) and a sidebar with their stats and the latest announcements.
 */
#[AsController]
class IndexAction extends AbstractController
{
    /** Announcements of the sidebar's "What's new". */
    private const int ANNOUNCEMENTS = 3;

    /** In-progress multiplayer games shown inline before "view all". */
    private const int YOUR_GAMES_LIMIT = 5;

    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly AnnouncementProvider $announcementProvider,
        private readonly NextBot $nextBot,
        private readonly BotGameManager $botGameManager,
        private readonly BotAccounts $botAccounts,
    ) {
    }

    #[Route(path: '/', name: 'index', methods: ['GET'])]
    public function __invoke(Request $request): array
    {
        $user = $this->getUser();
        $signedIn = $user instanceof User;
        $quickGame = $this->createForm(LocalGameType::class, null, ['guest' => !$signedIn])->createView();

        if (!$user instanceof User) {
            return ['quickGame' => $quickGame];
        }

        $botGame = $this->botGameManager->findCurrent($user);

        $multiplayer = array_values(array_filter(
            $this->gameRepository->findOngoingForUser($user),
            static fn (Game $game): bool => OpponentType::MULTIPLAYER === $game->getOpponentType() && !$game->isBotGame(),
        ));
        $yourTurn = array_values(array_filter($multiplayer, static fn (Game $game): bool => $game->isTurnOf($user)));
        $others = array_values(array_filter($multiplayer, static fn (Game $game): bool => !$game->isTurnOf($user)));
        $shownOthers = \array_slice($others, 0, self::YOUR_GAMES_LIMIT);
        $this->gameRepository->preloadForListing([...$yourTurn, ...$shownOthers, ...(null !== $botGame ? [$botGame] : [])]);

        $stats = $this->gameRepository->getFinishedGameStatsForUser($user);
        $totalFinished = $stats['wins'] + $stats['losses'] + $stats['draws'];

        return [
            'quickGame' => $quickGame,
            'greetingName' => self::greetingName($user),
            'nextBot' => $this->nextBot->forRequest($user, $request),
            'botGame' => $botGame,
            'runningBot' => null === $botGame ? null : [
                'level' => $botGame->getAiLevel(),
                'avatarUrl' => null === $botGame->getAiLevel() ? null : $this->botAccounts->avatarUrl($botGame->getAiLevel()),
                'plies' => \count($botGame->getMovesData()->getMoves()),
                'yourTurn' => $botGame->isTurnOf($user),
                'rated' => $botGame->isRated(),
                'url' => $this->generateUrl('play', ['uuid' => $botGame->getUuid()->toRfc4122()]),
            ],
            'yourTurnGames' => $yourTurn,
            'yourGames' => $shownOthers,
            'yourGamesCount' => \count($others),
            'stats' => $stats,
            'totalFinished' => $totalFinished,
            'winRate' => $totalFinished > 0 ? round(($stats['wins'] / $totalFinished) * 100) : null,
            'announcements' => $this->announcementProvider->latest(self::ANNOUNCEMENTS),
        ];
    }

    /** The display name, else the username - never the email (a display name copied from it does not count). */
    private static function greetingName(User $user): string
    {
        $displayName = trim((string) $user->getDisplayName());

        return '' !== $displayName && !str_contains($displayName, '@') ? $displayName : $user->getUsername();
    }
}
