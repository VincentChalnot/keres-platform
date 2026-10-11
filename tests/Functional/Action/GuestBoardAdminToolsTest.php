<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin-only "download the board as static HTML" button sits next to the flip button in
 * `_play_board.html.twig` behind `is_granted('ROLE_ADMIN')`: the markup of an anonymous visitor's board
 * page (the browser-only guest game) must not contain it. No database involved: the page is anonymous
 * and session-free.
 */
final class GuestBoardAdminToolsTest extends WebTestCase
{
    public function testAnonymousGuestBoardHasNoExportButton(): void
    {
        $client = static::createClient();
        $client->request('GET', '/play/guest');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="flip-board-btn"', $html);
        self::assertStringNotContainsString('export-board-btn', $html);
        self::assertStringNotContainsString('Download the board', $html);
    }
}
