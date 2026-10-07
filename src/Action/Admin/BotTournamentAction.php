<?php

declare(strict_types=1);

namespace App\Action\Admin;

use App\Service\BotTournament\BotTournamentReport;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Results of the bot-vs-bot games queued by `app:bot-tournament`.
 */
#[AsController]
class BotTournamentAction
{
    public function __construct(
        private readonly BotTournamentReport $report,
    ) {
    }

    #[Route(path: '/admin/bot-tournament', name: 'admin_bot_tournament', methods: ['GET'])]
    public function __invoke(): array
    {
        return ['report' => $this->report->build()];
    }
}
