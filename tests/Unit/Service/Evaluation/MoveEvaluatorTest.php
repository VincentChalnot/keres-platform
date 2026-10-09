<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Evaluation;

use App\Engine\EngineApi;
use App\Entity\BoardPosition;
use App\Entity\Game;
use App\Entity\Move;
use App\Entity\User;
use App\Model\BoardData;
use App\Model\MoveData;
use App\Model\MovesData;
use App\Model\OpponentType;
use App\Model\TimeControl;
use App\Service\Evaluation\MoveEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MoveEvaluatorTest extends TestCase
{
    public function testStartPositionIsBalancedWithoutCallingTheEngine(): void
    {
        $engine = $this->createMock(EngineApi::class);
        $engine->expects(self::never())->method('evaluateGame');

        $evaluator = new MoveEvaluator($engine, $this->createMock(EntityManagerInterface::class));

        self::assertSame(0, $evaluator->evaluatePly($this->gameWithMoves(2), 0));
    }

    public function testAnEvaluatedEdgeIsServedFromTheCache(): void
    {
        $game = $this->gameWithMoves(2);
        $game->getGameMoves()->last()->getMove()->setEvaluation(-37);

        $engine = $this->createMock(EngineApi::class);
        $engine->expects(self::never())->method('evaluateGame');

        $evaluator = new MoveEvaluator($engine, $this->createMock(EntityManagerInterface::class));

        self::assertSame(-37, $evaluator->evaluatePly($game, 2));
    }

    public function testAMissingEvaluationSendsTheWholeLineAndStoresTheResult(): void
    {
        $game = $this->gameWithMoves(3);

        $engine = $this->createMock(EngineApi::class);
        $engine->expects(self::once())->method('evaluateGame')
            ->with(self::callback(static fn (MovesData $moves): bool => 2 === $moves->getMoves()->count()))
            ->willReturn(120);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $evaluator = new MoveEvaluator($engine, $em);

        self::assertSame(120, $evaluator->evaluatePly($game, 2));
        self::assertSame(120, $game->getGameMoves()->get(1)->getMove()->getEvaluation());
        self::assertSame([0, null, 120, null], $evaluator->storedEvaluations($game));
    }

    public function testPlyBeyondTheGameIsRejected(): void
    {
        $evaluator = new MoveEvaluator($this->createMock(EngineApi::class), $this->createMock(EntityManagerInterface::class));

        $this->expectException(\OutOfRangeException::class);
        $evaluator->evaluatePly($this->gameWithMoves(1), 2);
    }

    private function gameWithMoves(int $count): Game
    {
        $game = new Game(new User('creator@example.com'), OpponentType::HOTSEAT, TimeControl::unlimited(), false);
        $position = new BoardPosition(new BoardData(str_repeat("\0", 81)."\x80\x00"));

        for ($i = 0; $i < $count; ++$i) {
            $game->addMove(new Move(new MoveData(pack('v', $i + 1)), $position, $position));
        }

        return $game;
    }
}
