<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Repository\GameRepository;
use App\Repository\SeekRepository;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /lobby` (04-matchmaking.md sec 9.2) - the multiplayer front door.
 * Anonymous-allowed HTML: presets, the custom-seek form, the live seek
 * table (server-rendered once, then kept live by `LobbyController`), and
 * the viewer's own in-progress games.
 */
#[AsController]
class LobbyAction extends AbstractController
{
    /** Mirrors QuickPairAction::PRESETS - display labels only, values are never trusted from the client. */
    private const array PRESET_LABELS = [
        '1+0' => ['label' => '1+0', 'speed' => 'Bullet'],
        '3+2' => ['label' => '3+2', 'speed' => 'Blitz'],
        '5+0' => ['label' => '5+0', 'speed' => 'Blitz'],
        '10+0' => ['label' => '10+0', 'speed' => 'Rapid'],
        '15+10' => ['label' => '15+10', 'speed' => 'Rapid'],
        'corr1' => ['label' => '1 day', 'speed' => 'Correspondence'],
        'corr3' => ['label' => '3 days', 'speed' => 'Correspondence'],
        'corr7' => ['label' => '7 days', 'speed' => 'Correspondence'],
    ];

    public function __construct(
        private readonly SeekRepository $seekRepository,
        private readonly SeekPayloadBuilder $seekPayloadBuilder,
        private readonly GameRepository $gameRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/lobby', name: 'lobby', methods: ['GET'])]
    public function __invoke(): array
    {
        $user = $this->getUser();
        $viewer = $user instanceof User ? $user : null;
        $now = $this->clock->now();

        $seeks = $this->seekRepository->findOpenForListing($now);
        $listing = $this->seekPayloadBuilder->buildListing($seeks, $viewer, \count($seeks), $now);

        return [
            'presets' => self::PRESET_LABELS,
            'seeksBootstrap' => $this->seekPayloadBuilder->encode($listing),
            'ongoingGames' => null !== $viewer ? $this->gameRepository->findOngoingForUser($viewer) : [],
        ];
    }
}
