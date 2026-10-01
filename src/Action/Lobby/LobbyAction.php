<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Repository\GameRepository;
use App\Repository\SeekRepository;
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
     * One preset per format. They only fill the "New seek" form in - the
     * posted values are whatever the form holds, validated by
     * `CreateSeekAction`. Keres games run 35-60 full moves, hence clocks
     * far longer than their chess namesakes (see `TimeControl::speedCategory()`).
     */
    public const array PRESETS = [
        'bullet' => ['label' => 'Bullet', 'kind' => 'realtime', 'initialMinutes' => 3, 'incrementSeconds' => 2],
        'blitz' => ['label' => 'Blitz', 'kind' => 'realtime', 'initialMinutes' => 7, 'incrementSeconds' => 5],
        'rapid' => ['label' => 'Rapid', 'kind' => 'realtime', 'initialMinutes' => 20, 'incrementSeconds' => 10],
        'classical' => ['label' => 'Classical', 'kind' => 'realtime', 'initialMinutes' => 100, 'incrementSeconds' => 0],
        'correspondence' => ['label' => 'Correspondence', 'kind' => 'correspondence', 'hoursPerMove' => 24],
    ];

    /** Pre-selected on page load. */
    private const string DEFAULT_PRESET = 'rapid';

    /**
     * T14: static for now ("static content is fine" per the brief) -
     * revisit with a real content source if announcements ever need to
     * be editable without a deploy.
     */
    private const array ANNOUNCEMENTS = [
        ['title' => 'Keres v1.0 is here', 'body' => 'Correspondence games, invites, and AI opponents at every level are now live.'],
        ['title' => 'Play without an account', 'body' => 'Try the AI or a local hot-seat game instantly - no sign-up required.'],
    ];

    /** How many public finished games the anonymous entry point shows. */
    private const int PUBLIC_GAMES_LIMIT = 5;

    /** How many of the viewer's own games are shown inline before "view all". */
    private const int OWN_GAMES_LIMIT = 5;

    public function __construct(
        private readonly SeekRepository $seekRepository,
        private readonly SeekPayloadBuilder $seekPayloadBuilder,
        private readonly GameRepository $gameRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/lobby', name: 'lobby', methods: ['GET'])]
    public function __invoke(): array|Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->render('actions/play_welcome.html.twig', [
                'announcements' => self::ANNOUNCEMENTS,
                'publicGames' => $this->gameRepository->findRecentPubliclyFinishedGames(self::PUBLIC_GAMES_LIMIT),
            ]);
        }

        $now = $this->clock->now();
        $seeks = $this->seekRepository->findOpenForListing($now);
        $listing = $this->seekPayloadBuilder->buildListing($seeks, $user, \count($seeks), $now);
        $ongoingGames = $this->gameRepository->findOngoingForUser($user);

        return [
            'presets' => self::PRESETS,
            'defaultPreset' => self::DEFAULT_PRESET,
            'seeksBootstrap' => $this->seekPayloadBuilder->encode($listing),
            'ongoingGames' => \array_slice($ongoingGames, 0, self::OWN_GAMES_LIMIT),
            'ongoingGamesCount' => \count($ongoingGames),
        ];
    }
}
