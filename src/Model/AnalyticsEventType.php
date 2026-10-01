<?php

declare(strict_types=1);

namespace App\Model;

/**
 * T6: append-only collection only, no dashboards/aggregation UI.
 *
 * INVITE_SENT/INVITE_ACCEPTED are recorded by T11's invite flow
 * (`CreateInviteAction`, `InviteAcceptAction`).
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
