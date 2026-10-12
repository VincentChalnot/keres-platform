<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\BoardPosition;
use App\Entity\Game;
use App\Entity\GameMove;
use App\Entity\Move;
use App\Entity\User;
use App\Model\BoardData;
use App\Model\MoveData;
use App\Service\UsernameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base of the tests that need the real PostgreSQL schema (`app_test`, migrated by `doctrine:migrations:migrate
 * --env=test`). Every test runs in a transaction rolled back by DAMA\DoctrineTestBundle, so nothing leaks from
 * one test to the next.
 *
 * Games are always created through the application services (`GameFactory`), never with raw SQL.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    /**
     * Server parameters of a same-origin request on the application's own host. The Mercure authorisation cookie
     * is only issued for the host of MERCURE_PUBLIC_URL (`app.local.playkeres.com` in dev, `localhost` in CI), so the
     * tests must ask for that host; the Origin header satisfies the stateless CSRF same-origin check (forms post the
     * literal `csrf-token` placeholder, see LostPasswordActionTest).
     *
     * @return array<string, string>
     */
    protected function sameOrigin(): array
    {
        $host = (string) parse_url((string) ($_SERVER['MERCURE_PUBLIC_URL'] ?? 'https://localhost'), \PHP_URL_HOST);

        return ['HTTP_HOST' => $host, 'HTTP_ORIGIN' => 'http://'.$host];
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Appends `$plies` moves to `$game`: real `GameMove` rows (so the ply counters and `hasReachedRatedPlyFloor()` see
     * them) over throwaway board positions. The moves are placeholders - no engine is called - so the position the game
     * ends up in means nothing; only what happens to the game around it (result, rating, ladder) is under test.
     */
    protected function addPlies(Game $game, int $plies): void
    {
        $from = new BoardPosition(new BoardData(random_bytes(81)."\x80\x00"));
        $this->em()->persist($from);

        for ($ply = 0; $ply < $plies; ++$ply) {
            $to = new BoardPosition(new BoardData(random_bytes(81)."\x80\x00"));
            $this->em()->persist($to);
            $move = new Move(new MoveData(random_bytes(2)), $from, $to);
            $this->em()->persist($move);
            $game->getGameMoves()->add(new GameMove($game, $move));
            $from = $to;
        }

        $this->em()->flush();
    }

    /**
     * @param string[] $roles
     */
    protected function createUser(string $email, array $roles = []): User
    {
        $user = new User($email);
        $user->setDisplayName($email);
        $user->setUsername(static::getContainer()->get(UsernameGenerator::class)->generate($email, $email));

        if ([] !== $roles) {
            $user->setRoles($roles);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }
}
