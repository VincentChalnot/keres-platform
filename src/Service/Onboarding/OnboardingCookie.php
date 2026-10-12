<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * "This browser has been through the tutorial (or dismissed the invitation)". Set by the server, one year, and read by
 * the onboarding banner and, at account creation, by `NewUserBrowserProgressListener` so that the flag follows the
 * visitor into their account.
 */
final class OnboardingCookie
{
    public const string NAME = 'keres_onboarded';

    public static function create(bool $secure): Cookie
    {
        return Cookie::create(
            name: self::NAME,
            value: '1',
            expire: new \DateTimeImmutable('+1 year'),
            path: '/',
            secure: $secure,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
