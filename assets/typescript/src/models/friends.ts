/**
 * Wire types for the friends page and player search
 * (05-social.md sec 3/4/9.2, 09-api-reference.md sec 4.3). Kept separate
 * from `seek.ts` - a different domain, sharing nothing but `rating`/
 * `provisional`'s shape.
 */

export interface RatingRow {
    rating: number;
    provisional: boolean;
    games: number;
}

export type RatingsByCategory = Record<'bullet' | 'blitz' | 'rapid' | 'classical' | 'correspondence', RatingRow>;

export interface FriendRow {
    username: string;
    displayName: string | null;
    avatarUrl: string | null;
    online: boolean;
    lastSeenAt: string | null;
    ratings: RatingsByCategory;
}

export interface FriendRequestRow {
    username: string;
    displayName: string | null;
    avatarUrl: string | null;
    createdAt: string | null;
}

export interface FriendsListResult {
    friends: FriendRow[];
    incoming: FriendRequestRow[];
    outgoing: FriendRequestRow[];
    blocked: FriendRequestRow[];
}

export interface FriendRequestResult {
    friendship: {username: string; status: 'pending' | 'accepted'};
}

export interface PlayerSearchResult {
    username: string;
    rating: number;
    provisional: boolean;
    online: boolean;
}

/** `UserEventPayload`, topic `user/{uuid}` (02-realtime.md sec 4.2). Only the two Phase 4 variants are typed; any other `event` is ignored by `FriendEventClient`'s caller. */
export interface UserEvent {
    type: 'user.event';
    event: string;
    notificationUuid: string | null;
    createdAt: number;
    unreadCount: number;
    data: Record<string, unknown>;
    serverTime: number;
}
