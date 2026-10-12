<?php

declare(strict_types=1);

namespace App\Service\Ladder;

use App\Form\LocalGameType;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a guest has achieved against the AI, kept by the browser in the `keres_ai_beaten` cookie (the highest level
 * beaten without undo, written by `utils/cookies.ts`): it drives the lobby's "next bot" and is copied to the account at
 * sign-up (`NewUserBrowserProgressListener`). Guests only play the levels up to `LocalGameType::GUEST_MAX_AI_LEVEL`, so
 * whatever the cookie says is capped there: it is a convenience, not a credential.
 */
final class GuestAiProgress
{
    public const string COOKIE = 'keres_ai_beaten';

    /** The highest level beaten according to the request's cookie: 0 when absent or not a level. */
    public static function levelBeaten(Request $request): int
    {
        $value = $request->cookies->get(self::COOKIE);

        if (!\is_string($value) || !ctype_digit($value)) {
            return 0;
        }

        return min((int) $value, LocalGameType::GUEST_MAX_AI_LEVEL);
    }
}
