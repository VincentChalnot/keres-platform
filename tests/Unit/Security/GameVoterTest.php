<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Security\Voter\GameVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Admins may look at every game but never play in somebody else's: VIEW only.
 */
final class GameVoterTest extends TestCase
{
    public function testAdminCanViewButNotPlayAnAiGameOfSomeoneElse(): void
    {
        $game = $this->aiGameOf(new User('player@example.com'));
        $admin = $this->token(new User('admin@example.com'), ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, $game, GameVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, $game, GameVoter::PARTICIPATE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, $game, GameVoter::MANAGE));
    }

    public function testOrdinaryUserCannotViewAnAiGameOfSomeoneElse(): void
    {
        $game = $this->aiGameOf(new User('player@example.com'));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->token(new User('other@example.com')), $game, GameVoter::VIEW));
    }

    public function testDeletedGameIsHiddenEvenFromAdmins(): void
    {
        $game = $this->aiGameOf(new User('player@example.com'));
        $game->setDeletedAt(new \DateTimeImmutable());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->token(new User('admin@example.com'), ['ROLE_ADMIN']), $game, GameVoter::VIEW));
    }

    private function aiGameOf(User $player): Game
    {
        $game = new Game($player, OpponentType::AI, TimeControl::unlimited(), false, 3);
        new GamePlayer($game, PieceColor::WHITE, $player);
        new GamePlayer($game, PieceColor::BLACK, null);

        return $game;
    }

    /**
     * @param string[] $roles
     */
    private function token(User $user, array $roles = []): TokenInterface
    {
        $user->setRoles($roles);

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function vote(TokenInterface $token, Game $game, string $attribute): int
    {
        return (new GameVoter())->vote($token, $game, [$attribute]);
    }
}
