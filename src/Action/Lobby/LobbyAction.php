<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Repository\SeekRepository;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /lobby` (04-matchmaking.md sec 9.2) - online play only, for signed-in players (`access_control`: an anonymous
 * visitor is sent to the login and comes back): the quick-pairing row, the live seek table (server-rendered once, then
 * kept live by `LobbyController`) and the "New game" modal holding the seek form. Bot games, the player's own games and
 * hot-seat live on the home page.
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

    public function __construct(
        private readonly SeekRepository $seekRepository,
        private readonly SeekPayloadBuilder $seekPayloadBuilder,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/lobby', name: 'lobby', methods: ['GET'])]
    public function __invoke(): array
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $now = $this->clock->now();
        $seeks = $this->seekRepository->findOpenForListing($now, $user);
        $poolSize = \count(array_filter($seeks, static fn ($seek): bool => null === $seek->getRematchOfGame()));
        $listing = $this->seekPayloadBuilder->buildListing($seeks, $user, $poolSize, $now);

        return [
            'presets' => self::PRESETS,
            'defaultPreset' => self::DEFAULT_PRESET,
            'seeksBootstrap' => $this->seekPayloadBuilder->encode($listing),
        ];
    }
}
