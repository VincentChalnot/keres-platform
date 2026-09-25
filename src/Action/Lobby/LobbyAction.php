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
        'correspondence' => ['label' => 'Correspondence', 'kind' => 'correspondence', 'daysPerMove' => 1],
    ];

    /** Pre-selected on page load. */
    private const string DEFAULT_PRESET = 'rapid';

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
            return $this->render('actions/play_welcome.html.twig');
        }

        $now = $this->clock->now();
        $seeks = $this->seekRepository->findOpenForListing($now);
        $listing = $this->seekPayloadBuilder->buildListing($seeks, $user, \count($seeks), $now);

        return [
            'presets' => self::PRESETS,
            'defaultPreset' => self::DEFAULT_PRESET,
            'seeksBootstrap' => $this->seekPayloadBuilder->encode($listing),
            'ongoingGames' => $this->gameRepository->findOngoingForUser($user),
        ];
    }
}
