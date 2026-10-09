/**
 * The game-over banner wording. The platform stores and relays the engine's
 * game-over code opaquely (`Game::$engineEndCode`); what each value means is
 * known here only - the codes are those of `POST /game-over-reason` in the
 * engine's `docs/PROTOCOL.md`.
 */

/** How a game ended, from `Game::getEndReason()` (`endReason` of the game-state payload). */
const END_REASON_SUFFIX: Record<string, string> = {
    resignation: 'by resignation',
    timeout: 'on time',
    abandonment: 'by abandonment',
    draw_agreed: 'by agreement',
};

/** Which engine rule ended an engine-ended game (`engineEndCode`). */
const ENGINE_END_SUFFIX: Record<number, string> = {
    1: 'by king capture',
    2: 'by the 40-move rule',
    3: 'by insufficient material',
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
        return 'Game aborted.';
    }

    const suffix = 'engine' === endReason
        ? (null === engineEndCode ? undefined : ENGINE_END_SUFFIX[engineEndCode])
        : END_REASON_SUFFIX[endReason];
    const withSuffix = (text: string): string => (suffix ? `${text} ${suffix}.` : `${text}.`);

    if ('draw' === result) return withSuffix('Draw');
    if ('white' === result) return withSuffix('White wins');
    if ('black' === result) return withSuffix('Black wins');

    return null;
}
