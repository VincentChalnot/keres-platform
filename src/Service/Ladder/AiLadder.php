<?php

declare(strict_types=1);

namespace App\Service\Ladder;

use App\Entity\Game;
use App\Entity\User;
use App\Model\GameEndReason;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Service\BotTournament\BotAccounts;

/**
 * The AI ladder: the highest engine level a player has beaten (`User::$highestAiLevelBeaten`), which decides the next bot
 * the lobby offers. A win counts against the AI (casual game, level in `Game::$aiLevel`) as well as in a rated game
 * against a bot account, provided no undo was used in an AI game. Draws, resignations of the player and aborted games
 * count for nothing. Called by `GameLifecycleManager` as the game is finalised, inside its transaction (the caller
 * flushes).
 */
final class AiLadder
{
    public function recordFinishedGame(Game $game): void
    {
        $level = $game->getAiLevel();

        if (null === $level || $game->isDraw() || GameEndReason::ABORTED === $game->getEndReason()) {
            return;
        }

        $winner = $game->getPlayer($game->isWhiteWins() ? PieceColor::WHITE : PieceColor::BLACK)->getUser();

        if (!$winner instanceof User) {
            return; // the engine won
        }

        $beatTheMachine = match ($game->getOpponentType()) {
            OpponentType::AI => !$game->isUndoUsed(),
            OpponentType::MULTIPLAYER => $game->isBotGame() && null === BotAccounts::levelOfUser($winner),
            default => false,
        };

        if ($beatTheMachine) {
            $winner->recordAiLevelBeaten($level);
        }
    }
}
