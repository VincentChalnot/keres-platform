/**
 * Vertical evaluation bar (chess.com style): the white share of the bar
 * follows White's advantage, black fills the rest.
 *
 * Scores are the engine's units, always from White's point of view: a soldier
 * is worth 10, a decided game is +/-1000 (a forced win).
 */

/** |score| from which the engine reports a forced result rather than a material edge. */
const DECIDED_SCORE = 500;
/** Score at which the bar sits at ~73% / 27%: four soldiers' worth. */
const BAR_SCALE = 40;
const MIN_SHARE = 3;
const MAX_SHARE = 97;

/** Share of the bar (0-100) painted white for a score. */
export function whiteShare(score: number): number {
    if (score >= DECIDED_SCORE) return 100;
    if (score <= -DECIDED_SCORE) return 0;
    const share = 100 / (1 + Math.exp(-score / BAR_SCALE));

    return Math.min(MAX_SHARE, Math.max(MIN_SHARE, share));
}

/** Human label: pawn-like units with a sign ("+1.5"), or the result when decided. */
export function formatEvaluation(score: number): string {
    if (score >= DECIDED_SCORE) return '1-0';
    if (score <= -DECIDED_SCORE) return '0-1';
    const units = score / 10;
    const text = Math.abs(units).toFixed(1);

    return units > 0 ? `+${text}` : units < 0 ? `-${text}` : '0.0';
}

export class EvalBar {
    constructor(
        private readonly root: HTMLElement,
        private readonly white: HTMLElement,
        private readonly label: HTMLElement,
        private readonly layout: HTMLElement | null,
    ) {
    }

    /** Shows or hides the bar, reserving (or not) its room next to the board. */
    setVisible(visible: boolean): void {
        this.root.hidden = !visible;
        this.layout?.classList.toggle('has-eval-bar', visible);
    }

    /** Mirrors the bar with the board so the viewer's side stays at the bottom. */
    setFlipped(flipped: boolean): void {
        this.root.classList.toggle('is-flipped', flipped);
    }

    /**
     * `null` = not known yet: the bar stays where it was (nothing at all on a
     * fresh page: it rests at the middle with no label), marked as pending
     * until the verdict arrives.
     */
    setScore(score: number | null): void {
        this.root.classList.toggle('is-pending', null === score);

        if (null === score) {
            return;
        }

        const text = formatEvaluation(score);
        this.white.style.height = `${whiteShare(score)}%`;
        this.label.textContent = text;
        this.label.classList.toggle('is-white-ahead', score >= 0);
        this.label.classList.toggle('is-black-ahead', score < 0);
        this.root.title = `Engine evaluation: ${text}`;
        this.root.setAttribute('aria-label', `Engine evaluation: ${text}`);
    }
}
