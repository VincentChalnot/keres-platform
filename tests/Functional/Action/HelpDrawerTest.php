<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;

final class HelpDrawerTest extends DatabaseTestCase
{
    public function testTheGamePageHasTheHelpDrawerInThePlayersOrientation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->stubEngine();
        $user = $this->createUser('player@example.com');
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($user, OpponentType::AI, ColorPreference::BLACK, 2);
        $this->em()->persist($game);
        $this->em()->flush();
        $client->loginUser($user);

        $client->request('GET', '/play/'.$game->getUuid()->toRfc4122(), [], [], $this->sameOrigin());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="help-btn"', $content);
        self::assertStringContainsString('id="help-drawer"', $content);
        self::assertStringContainsString('data-help-piece="ballista"', $content);
        self::assertStringContainsString('/help/pieces/ballista-black.svg', $content, 'Black plays this game: the diagrams are the Black ones');
        self::assertStringNotContainsString('/help/pieces/ballista-white.svg', $content);
        self::assertStringContainsString('id="piece-diagram-modal"', $content);
    }
}
