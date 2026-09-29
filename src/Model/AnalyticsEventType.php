<?php

declare(strict_types=1);

namespace App\Model;

/**
 * T6: append-only collection only, no dashboards/aggregation UI.
 *
 * INVITE_SENT/INVITE_ACCEPTED have no live trigger yet - the invite/
 * challenge mechanism itself doesn't exist in the codebase yet (confirmed:
 * no Challenge/Invite entity, action, route, or repository anywhere;
 * 05-social.md only mentions it as planned). Defined now so a later task
 * doesn't have to touch this infrastructure again.
 */
enum AnalyticsEventType: string
{
    case ACCOUNT_CREATED = 'account_created';
    case FIRST_GAME_STARTED = 'first_game_started';
    case GAME_STARTED = 'game_started';
    case MOVE_PLAYED = 'move_played';
    case GAME_FINISHED = 'game_finished';
    case GAME_ABANDONED = 'game_abandoned';
    case INVITE_SENT = 'invite_sent';
    case INVITE_ACCEPTED = 'invite_accepted';
}
