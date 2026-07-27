<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Game;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Restricts access to a Game to its owner.
 *
 * A Game currently has a single owner: HOTSEAT games are played by that
 * owner against themselves on one device, AI games against the engine.
 * There is no second human player to grant access to.
 */
class GameVoter extends Voter
{
    public const string ACCESS = 'GAME_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute && $subject instanceof Game;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$subject instanceof Game) {
            return false;
        }

        $user = $token->getUser();

        return $user instanceof User && $user === $subject->getOwner();
    }
}
