<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\BoardPosition;
use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\Move;
use App\Entity\User;
use App\Message\EvaluateMoveMessage;
use App\MessageHandler\EvaluateMoveHandler;
use App\Model\BoardData;
use App\Model\MoveData;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Repository\GameRepository;
use App\Service\Evaluation\MoveEvaluator;
use App\Service\Game\GameUpdatePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class EvaluateMoveHandlerTest extends TestCase
{
    public function testAnEvaluationOfAnOptedInGameIsPushedAsAnEvaluationEvent(): void
    {
        $game = $this->gameWithMoves(rated: false, liveEvaluation: true, moves: 2);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            static fn (Update $update): bool => 'evaluation' === $update->getType()
                && !$update->isPrivate()
                && ['game/'.$game->getUuid()->toRfc4122()] === $update->getTopics()
                && '{"ply":2,"evaluation":80}' === $update->getData(),
        ));

        $this->handler($game, 80, $hub)(new EvaluateMoveMessage($game->getUuid()->toRfc4122(), 2));
    }

    public function testARatedGameInProgressStoresTheEvaluationButNeverPublishesIt(): void
    {
        $game = $this->gameWithMoves(rated: true, liveEvaluation: true, moves: 2);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $this->handler($game, 80, $hub, expectEvaluation: true)(new EvaluateMoveMessage($game->getUuid()->toRfc4122(), 2));
    }

    public function testAPlyBeyondTheGameAfterAnUndoIsIgnored(): void
    {
        $game = $this->gameWithMoves(rated: false, liveEvaluation: true, moves: 1);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $this->handler($game, 80, $hub, expectEvaluation: false)(new EvaluateMoveMessage($game->getUuid()->toRfc4122(), 2));
    }

    private function handler(Game $game, int $evaluation, HubInterface $hub, bool $expectEvaluation = true): EvaluateMoveHandler
    {
        $repository = $this->createMock(GameRepository::class);
        $repository->method('findAnyByUuid')->willReturn($game);

        $evaluator = $this->createMock(MoveEvaluator::class);
        $evaluator->expects($expectEvaluation ? self::once() : self::never())->method('evaluatePly')->willReturn($evaluation);

        return new EvaluateMoveHandler($repository, $evaluator, new GameUpdatePublisher($hub, new NullLogger()));
    }

    private function gameWithMoves(bool $rated, bool $liveEvaluation, int $moves): Game
    {
        $game = new Game(new User('creator@example.com'), OpponentType::MULTIPLAYER, TimeControl::realtime(300, 0), $rated, null, $liveEvaluation);
        new GamePlayer($game, PieceColor::WHITE, new User('white@example.com'));
        new GamePlayer($game, PieceColor::BLACK, new User('black@example.com'));
        $position = new BoardPosition(new BoardData(str_repeat("\0", 81)."\x80\x00"));

        for ($i = 0; $i < $moves; ++$i) {
            $game->addMove(new Move(new MoveData(pack('v', $i + 1)), $position, $position));
        }

        return $game;
    }
}
