/**
 * The game-over banner wording. The platform stores and relays the engine's
 * game-over code opaquely (`Game::$engineEndCode`); what each value means is
 * known here only - the codes are those of `POST /game-over-reason` in the
 * engine's `docs/PROTOCOL.md`.
 */
import {t} from '../i18n';

/**
 * How a game ended, from `Game::getEndReason()` (`endReason` of the game-state payload):
 * the reasons that need no engine code. Each is a branch of the `play.over.*` messages.
 */
const END_REASONS: Record<string, true> = {resignation: true, timeout: true, abandonment: true, draw_agreed: true};

/** Which engine rule ended an engine-ended game (`engineEndCode`), as a branch of the `play.over.*` messages. */
const ENGINE_END_REASONS: Record<number, string> = {
    1: 'king_capture',
    2: 'forty_move',
    3: 'insufficient_material',
};

export type GameOverOutcome = 'won' | 'lost' | 'drawn' | 'neutral';

/**
 * How a finished game looks from where the viewer stands, for the banner colour.
 * `viewer` is the colour the viewer plays, or null when they play neither (a
 * spectator) or both (hot-seat): a decisive result is then just 'neutral', as
 * is an aborted game or a result not known yet.
 */
export function gameOverOutcome(endReason: string, result: string | null, viewer: 'white' | 'black' | null): GameOverOutcome {
    if ('aborted' === endReason) return 'neutral';
    if ('draw' === result) return 'drawn';
    if (null === viewer || ('white' !== result && 'black' !== result)) return 'neutral';

    return result === viewer ? 'won' : 'lost';
}

/**
 * The banner text of a finished game. An engine ending whose code is unknown
 * (games finished before the code was stored, a code this client does not
 * know) gets no reason at all rather than a wrong one.
 */
export function describeGameOver(endReason: string, engineEndCode: number | null, result: string | null): string | null {
    if ('aborted' === endReason) {
        return t('play.over.aborted');
    }

    // Anything else selects the "no reason" branch of the message.
    const reason = 'engine' === endReason
        ? (null === engineEndCode ? undefined : ENGINE_END_REASONS[engineEndCode])
        : (END_REASONS[endReason] ? endReason : undefined);
    const params = {reason: reason ?? 'unknown'};

    if ('draw' === result) return t('play.over.draw', params);
    if ('white' === result) return t('play.over.white_wins', params);
    if ('black' === result) return t('play.over.black_wins', params);

    return null;
}
