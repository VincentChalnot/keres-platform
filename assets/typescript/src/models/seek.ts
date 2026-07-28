/**
 * Wire types shared by `LobbyAPI` (HTTP) and `LobbySeekClient` (SSE) -
 * one shape, per `02-realtime.md` sec 4.0/4.3 and `04-matchmaking.md`
 * sec 5.1/5.2. Kept in `models/` so neither network module has to import
 * from the other.
 */

export interface PlayerRef {
    uuid: string;
    username: string;
    rating: number;
    provisional: boolean;
}

export type ClockKind = 'unlimited' | 'realtime' | 'correspondence';

export interface TimeControlRef {
    kind: ClockKind;
    initialSeconds: number | null;
    incrementSeconds: number | null;
    daysPerMove: number | null;
    speed: string | null;
}

export type ColorPreference = 'white' | 'black' | 'random';

export interface SeekSummary {
    uuid: string;
    user: PlayerRef;
    timeControl: TimeControlRef;
    rated: boolean;
    color: ColorPreference;
    ratingRange: {min: number | null; max: number | null};
    autoWiden: boolean;
    createdAt: number;
    /** null for an anonymous viewer */
    self: boolean | null;
    /** null for an anonymous viewer */
    playable: boolean | null;
}

export interface SeekListing {
    seeks: SeekSummary[];
    poolSize: number;
    serverTime: number;
}

export type SeekRemovedReason = 'matched' | 'canceled' | 'expired' | 'replaced';

export interface SeekEvent {
    type: 'seek.added' | 'seek.removed';
    seekUuid: string;
    seek: SeekSummary | null;
    reason: SeekRemovedReason | null;
    poolSize: number;
    serverTime: number;
}

export interface SeekCreateResult {
    seek: SeekSummary;
    matched: {gameUuid: string} | null;
    deduped: boolean;
}

export interface HeartbeatResult {
    status: 'open' | 'matched';
    gameUuid: string | null;
    widenedTo: {min: number; max: number} | null;
}

export type QuickPairPreset = '1+0' | '3+2' | '5+0' | '10+0' | '15+10' | 'corr1' | 'corr3' | 'corr7';

export interface CustomSeekInput {
    kind: ClockKind;
    initialSeconds?: number | null;
    incrementSeconds?: number | null;
    daysPerMove?: number | null;
    rated: boolean;
    colorPreference: ColorPreference;
    ratingMin?: number | null;
    ratingMax?: number | null;
    autoWiden?: boolean;
}
