<?php

declare(strict_types=1);

namespace App\Model\Social;

/**
 * `FriendshipManager::relationOf($viewer, $subject)` (05-social.md sec 9.1).
 * Deliberately has no `blocked_by_them` case: that value is never computed
 * for the viewer, which is what keeps a block silent at the template level
 * (05-social.md sec 4.3) instead of relying on convention.
 */
enum Relationship: string
{
    case NONE = 'none';
    case PENDING_OUT = 'pending_out';
    case PENDING_IN = 'pending_in';
    case FRIENDS = 'friends';
    case BLOCKED_BY_ME = 'blocked_by_me';
}
