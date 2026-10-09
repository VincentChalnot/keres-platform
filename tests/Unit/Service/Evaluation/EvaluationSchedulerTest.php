<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Evaluation;

use App\Engine\EngineApi;
use App\Entity\BoardPosition;
use App\Entity\Game;
use App\Entity\Move;
use App\Entity\User;
use App\Message\EvaluateMoveMessage;
use App\Model\BoardData;
use App\Model\MoveData;
use App\Model\OpponentType;
use App\Model\TimeControl;
use App\Service\Evaluation\EvaluationScheduler;
use App\Service\Evaluation\MoveEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EvaluationSchedulerTest extends TestCase
{
    public function testOnlyThePliesWithoutStoredEvaluationAreQueued(): void
    {
        $game = $this->gameWithMoves(3);
        $game->getGameMoves()->get(1)->getMove()->setEvaluation(55);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (EvaluateMoveMessage $message) use (&$dispatched): Envelope {
            $dispatched[] = $message->ply;

            return new Envelope($message);
        });

        $engine = $this->createMock(EngineApi::class);
        $engine->expects(self::never())->method('evaluateGame');
        $em = $this->createMock(EntityManagerInterface::class);
        $scheduler = new EvaluationScheduler(new MoveEvaluator($engine, $em), $bus, $em);

        // Returns at once with what is stored; ply 0 is the balanced start position.
        self::assertSame([0, null, 55, null], $scheduler->scheduleMissing($game));
        self::assertSame([3, 1], $dispatched);
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
