<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Game;
use App\Entity\User;
use App\Model\OpponentType;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class GameVoter extends Voter
{
    public const string VIEW = 'GAME_VIEW';
    public const string PARTICIPATE = 'GAME_PARTICIPATE';
    public const string MANAGE = 'GAME_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Game
            && \in_array($attribute, [self::VIEW, self::PARTICIPATE, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $member = $user instanceof User && $subject->isParticipant($user);

        return match ($attribute) {
            self::VIEW => null === $subject->getDeletedAt()
                && (OpponentType::MULTIPLAYER === $subject->getOpponentType() || $member),
            self::PARTICIPATE => null === $subject->getDeletedAt() && $member,
            self::MANAGE => $member,
        };
    }
}
