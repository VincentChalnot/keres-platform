/**
 * The evaluation curve of the whole game, under the board: one point per ply (ply 0 is the starting position, 50%),
 * White's share painted white over black like the evaluation bar and with the same scale (`whiteShare`). A click jumps
 * to the nearest ply; the vertical line is the displayed one. Hand-drawn SVG: the design system styles it
 * (`.eval-graph*`), nothing here knows about colours.
 */
import {whiteShare} from './EvalBar';

const SVG_NS = 'http://www.w3.org/2000/svg';
/** Height of the viewBox (percent of the bar: 0 = black wins, 100 = white wins). */
const HEIGHT = 100;

export class EvalGraph {
    private selectHandler: ((ply: number) => void) | null = null;
    private flipped = false;
    private totalPlies = 0;

    constructor(private readonly root: HTMLElement) {
        root.addEventListener('click', (event) => {
            if (this.totalPlies <= 0) return;

            const rect = root.getBoundingClientRect();
            if (0 === rect.width) return;

            const fraction = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
            this.selectHandler?.(Math.round(fraction * this.totalPlies));
        });
    }

    setVisible(visible: boolean): void {
        this.root.hidden = !visible;
    }

    /** Turns the graph upside down with the board, like the bar: the viewer's side stays at the bottom (time still runs left to right). */
    setFlipped(flipped: boolean): void {
        this.flipped = flipped;
        this.applyOrientation(this.root.firstElementChild);
    }

    onSelectPly(handler: (ply: number) => void): void {
        this.selectHandler = handler;
    }

    private applyOrientation(svg: Element | null): void {
        if (svg instanceof SVGElement) {
            svg.style.transform = this.flipped ? 'scaleY(-1)' : '';
        }
    }

    /**
     * `scores` is the engine's verdict by ply (White's point of view); a ply that is missing interrupts the curve
     * there instead of being guessed.
     */
    render(scores: ReadonlyMap<number, number>, totalPlies: number, currentPly: number): void {
        this.totalPlies = totalPlies;

        if (totalPlies < 1) {
            this.root.replaceChildren();

            return;
        }

        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${totalPlies} ${HEIGHT}`);
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('aria-hidden', 'true');
        this.applyOrientation(svg);

        // Areas under the curve, one per run of plies with a known verdict
        const known = (ply: number): number | null => (0 === ply ? 0 : (scores.get(ply) ?? null));
        let run: string[] = [];
        const flush = (): void => {
            if (run.length >= 2) {
                const path = document.createElementNS(SVG_NS, 'path');
                path.setAttribute('class', 'eval-graph__area');
                const last = run[run.length - 1].split(',')[0];
                const first = run[0].split(',')[0];
                path.setAttribute('d', `M${first},${HEIGHT} L${run.join(' L')} L${last},${HEIGHT} Z`);
                svg.appendChild(path);
            }
            run = [];
        };

        for (let ply = 0; ply <= totalPlies; ply++) {
            const score = known(ply);
            if (null === score) {
                flush();
                continue;
            }
            run.push(`${ply},${(HEIGHT - whiteShare(score)).toFixed(2)}`);
        }
        flush();

        const middle = document.createElementNS(SVG_NS, 'line');
        middle.setAttribute('class', 'eval-graph__mid');
        middle.setAttribute('x1', '0');
        middle.setAttribute('x2', String(totalPlies));
        middle.setAttribute('y1', String(HEIGHT / 2));
        middle.setAttribute('y2', String(HEIGHT / 2));
        svg.appendChild(middle);

        const cursor = document.createElementNS(SVG_NS, 'line');
        cursor.setAttribute('class', 'eval-graph__cursor');
        cursor.setAttribute('x1', String(currentPly));
        cursor.setAttribute('x2', String(currentPly));
        cursor.setAttribute('y1', '0');
        cursor.setAttribute('y2', String(HEIGHT));
        svg.appendChild(cursor);

        this.root.replaceChildren(svg);
    }
}
