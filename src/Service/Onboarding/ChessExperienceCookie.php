<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Model\ChessExperience;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Do you play chess?" as answered in this browser (a `ChessExperience` value, one year). Read for anonymous
 * visitors and, at account creation, by `NewUserBrowserProgressListener`, which copies it to the account; a signed-in
 * user's account is the authority (`ChessExperienceResolver`), the cookie is then only a mirror.
 */
final class ChessExperienceCookie
{
    public const string NAME = 'keres_chess_xp';

    public static function read(Request $request): ?ChessExperience
    {
        return ChessExperience::tryFrom((string) $request->cookies->get(self::NAME));
    }

    public static function create(ChessExperience $experience, bool $secure): Cookie
    {
        return Cookie::create(
            name: self::NAME,
            value: $experience->value,
            expire: new \DateTimeImmutable('+1 year'),
            path: '/',
            secure: $secure,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
