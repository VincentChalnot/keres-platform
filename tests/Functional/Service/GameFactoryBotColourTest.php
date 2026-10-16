<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;

/**
 * D9: the one-click bot card puts the human on White at level 1.
 */
final class GameFactoryBotColourTest extends DatabaseTestCase
{
    public function testLevelOneWithARandomPreferencePutsTheHumanOnWhite(): void
    {
        static::bootKernel();
        $human = $this->createUser('human@example.com');
        $factory = static::getContainer()->get(GameFactory::class);
        $bot = $this->createUser('bot@example.com');

        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(PieceColor::WHITE, $factory->createAiOrHotseatGame($human, OpponentType::AI, ColorPreference::RANDOM, 1)->getCreatorColor());
            self::assertSame(PieceColor::WHITE, $factory->createBotGame($human, $bot, 1, ColorPreference::RANDOM, TimeControl::realtime(420, 5))->getCreatorColor());
        }

        self::assertSame(PieceColor::BLACK, $factory->createAiOrHotseatGame($human, OpponentType::AI, ColorPreference::BLACK, 1)->getCreatorColor(), 'an explicit choice is respected');

        $colours = [];

        for ($i = 0; $i < 60; ++$i) {
            $colours[$factory->createAiOrHotseatGame($human, OpponentType::AI, ColorPreference::RANDOM, 2)->getCreatorColor()->name] = true;
        }

        self::assertCount(2, $colours, 'above level 1 the colour stays random');
    }
}
