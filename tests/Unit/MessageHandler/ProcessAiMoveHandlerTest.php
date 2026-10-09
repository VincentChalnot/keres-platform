<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Exception\GameAlreadyFinishedException;
use App\Exception\StalePositionException;
use App\Message\ProcessAiMoveMessage;
use App\MessageHandler\ProcessAiMoveHandler;
use App\Model\BoardData;
use App\Model\BoardMovesData;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Repository\GameRepository;
use App\Service\Game\GameStatePayloadBuilder;
use App\Service\Game\GameUpdatePublisher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class ProcessAiMoveHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function lostRaces(): iterable
    {
        yield 'stale position' => [new StalePositionException()];
        yield 'game already finished' => [new GameAlreadyFinishedException()];
    }

    #[DataProvider('lostRaces')]
    public function testLosingThePostLockRaceIsExpectedAndPublishesTheCurrentState(\Throwable $race): void
    {
        $game = new Game(new User('creator@example.com'), OpponentType::AI, TimeControl::unlimited(), false, 1);
        new GamePlayer($game, PieceColor::WHITE, new User('white@example.com'));
        new GamePlayer($game, PieceColor::BLACK, null);
        $current = new BoardMovesData(new BoardData(str_repeat("\0", 81)."\x80\x00"), $game->getMovesData());

        $engine = $this->createMock(GameEngine::class);
        $engine->expects(self::once())->method('aiMove')->willThrowException($race);
        $engine->expects(self::once())->method('getBoardMovesData')->with($game)->willReturn($current);

        $repository = $this->createMock(GameRepository::class);
        $repository->method('findByUuid')->willReturn($game);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish');

        $handler = new ProcessAiMoveHandler($repository, $engine, new GameUpdatePublisher($hub, new NullLogger()), new GameStatePayloadBuilder());

        $handler(new ProcessAiMoveMessage($game->getUuid()->toRfc4122(), 0));
    }
}
