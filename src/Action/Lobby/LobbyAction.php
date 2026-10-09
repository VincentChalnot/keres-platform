<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Repository\GameRepository;
use App\Repository\SeekRepository;
use App\Service\Announcement\AnnouncementProvider;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /lobby` (04-matchmaking.md sec 9.2) - the multiplayer front door.
 * Signed-in players get the "New seek" panel, the live seek table
 * (server-rendered once, then kept live by `LobbyController`) and their
 * in-progress games. Anonymous visitors get the sign-in prompt instead,
 * with playing the AI or hot-seat without an account as the fallback.
 */
#[AsController]
class LobbyAction extends AbstractController
{
    /**
     * One preset per format; `label` is a `game`-domain translation key. They only fill the "New seek" form in - the
     * posted values are whatever the form holds, validated by
     * `CreateSeekAction`. Keres games run 35-60 full moves, hence clocks
     * far longer than their chess namesakes (see `TimeControl::speedCategory()`).
     */
    public const array PRESETS = [
        'bullet' => ['label' => 'speed.bullet', 'kind' => 'realtime', 'initialMinutes' => 3, 'incrementSeconds' => 2],
        'blitz' => ['label' => 'speed.blitz', 'kind' => 'realtime', 'initialMinutes' => 7, 'incrementSeconds' => 5],
        'rapid' => ['label' => 'speed.rapid', 'kind' => 'realtime', 'initialMinutes' => 20, 'incrementSeconds' => 10],
        'classical' => ['label' => 'speed.classical', 'kind' => 'realtime', 'initialMinutes' => 100, 'incrementSeconds' => 0],
        'correspondence' => ['label' => 'speed.correspondence', 'kind' => 'correspondence', 'hoursPerMove' => 24],
    ];

    /** Pre-selected on page load. */
    private const string DEFAULT_PRESET = 'rapid';

    /** How many public finished games the anonymous entry point shows. */
    private const int PUBLIC_GAMES_LIMIT = 5;

    /** How many of the viewer's own games are shown inline before "view all". */
    private const int OWN_GAMES_LIMIT = 5;

    public function __construct(
        private readonly SeekRepository $seekRepository,
        private readonly SeekPayloadBuilder $seekPayloadBuilder,
        private readonly GameRepository $gameRepository,
        private readonly ClockInterface $clock,
        private readonly AnnouncementProvider $announcementProvider,
    ) {
    }

    #[Route(path: '/lobby', name: 'lobby', methods: ['GET'])]
    public function __invoke(): array|Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            $publicGames = $this->gameRepository->findRecentPubliclyFinishedGames(self::PUBLIC_GAMES_LIMIT);
            $this->gameRepository->preloadForListing($publicGames);

            return $this->render('actions/play_welcome.html.twig', [
                'announcements' => $this->announcementProvider->latest(),
                'publicGames' => $publicGames,
            ]);
        }

        $now = $this->clock->now();
        $seeks = $this->seekRepository->findOpenForListing($now);
        $listing = $this->seekPayloadBuilder->buildListing($seeks, $user, \count($seeks), $now);
        $ongoingGames = $this->gameRepository->findOngoingForUser($user);
        $shownGames = \array_slice($ongoingGames, 0, self::OWN_GAMES_LIMIT);
        $this->gameRepository->preloadForListing($shownGames);

        return [
            'presets' => self::PRESETS,
            'defaultPreset' => self::DEFAULT_PRESET,
            'seeksBootstrap' => $this->seekPayloadBuilder->encode($listing),
            'ongoingGames' => $shownGames,
            'ongoingGamesCount' => \count($ongoingGames),
        ];
    }
}
