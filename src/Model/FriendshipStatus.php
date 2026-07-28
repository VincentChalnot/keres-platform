<?php

declare(strict_types=1);

namespace App\Model;

/**
 * `Friendship.status` (05-social.md sec 3.1, 01-domain-model.md sec 6.7).
 * `ACCEPTED` is semantically undirected - direction only records who asked.
 * `BLOCKED` is strictly directional (F2): `A->B BLOCKED` and `B->A BLOCKED`
 * may coexist as two separate rows.
 */
enum FriendshipStatus: int
{
    case PENDING = 0;
    case ACCEPTED = 1;
    case DECLINED = 2;
    case BLOCKED = 3;
}
