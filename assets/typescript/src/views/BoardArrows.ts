import {BOARD_SIZE, LAST_BOARD_INDEX} from '../models/types';

/**
 * Analysis arrows drawn on the board with the right mouse button, chess.com style.
 *
 * Purely visual state of the view: arrows are plain `{from, to, color}` records keyed by logical tile index
 * (the one `onTileClick` reports), drawn into a dedicated `<g id="arrows-layer">` of the board SVG through
 * the view's flip-aware tile geometry, and styled by the `.arrow*` classes of `assets/board.css` only (so a
 * static export of the SVG needs nothing else). They know nothing about turns, locks or game state; whoever
 * owns the displayed position decides when to `clear()` them.
 */

export type ArrowColor = 'orange' | 'green' | 'red';

export interface BoardArrow {
    from: number;
    to: number;
    color: ArrowColor;
}

export interface BoardArrowsHost {
    /** The board SVG: the right button is pressed on it, the browser context menu is suppressed on it. */
    svg: SVGSVGElement;
    /** `<g id="arrows-layer">`, above the pieces. */
    layer: SVGGElement;
    /** Centre of a logical tile in SVG user units, taking the board orientation into account. */
    tileCentre(index: number): {x: number; y: number};
    /** Logical tile under a mouse event, null outside the 9×9 board. */
    tileAt(event: MouseEvent): number | null;
    /** False while something else owns the mouse (a piece is being dragged). */
    canStart(): boolean;
}

const SVG_NS = 'http://www.w3.org/2000/svg';

const COLORS: readonly ArrowColor[] = ['orange', 'green', 'red'];

// Arrow dimensions in SVG user units (a tile is 100 × 80).
const TILE_HALF_WIDTH = 50;
const TILE_HALF_HEIGHT = 40;
const START_ELLIPSE_RATIO = 0.8; // size of the ellipse (relative to the tile) arrows start from
const SHAFT_HALF_WIDTH = 9;
const HEAD_HALF_WIDTH = 24;
const HEAD_LENGTH = 34;

/** Long enough that the context menu of a release outside the board (Windows opens it on mouse-up) is still swallowed. */
const CONTEXT_MENU_GRACE_MS = 250;

interface Point {
    x: number;
    y: number;
}

/** Plain right-click: orange; Shift: green; Ctrl: red (Ctrl wins over Shift). */
function colorForModifiers(modifiers: {shiftKey: boolean; ctrlKey: boolean}): ArrowColor {
    if (modifiers.ctrlKey) return 'red';
    if (modifiers.shiftKey) return 'green';
    return 'orange';
}

function isValidArrow(arrow: BoardArrow): boolean {
    return Number.isInteger(arrow.from) && arrow.from >= 0 && arrow.from <= LAST_BOARD_INDEX
        && Number.isInteger(arrow.to) && arrow.to >= 0 && arrow.to <= LAST_BOARD_INDEX
        && arrow.from !== arrow.to
        && COLORS.includes(arrow.color);
}

/**
 * Knight-like relation of two tiles (exactly (1,2) or (2,1) tiles apart, whatever stands on them): the arrow
 * bends, going along the long axis first. 'none' for every other pair, which gets a straight arrow.
 */
function bendOf(from: number, to: number): 'none' | 'vertical-first' | 'horizontal-first' {
    const columns = Math.abs((to % BOARD_SIZE) - (from % BOARD_SIZE));
    const rows = Math.abs(Math.floor(to / BOARD_SIZE) - Math.floor(from / BOARD_SIZE));
    if (columns === 1 && rows === 2) return 'vertical-first';
    if (columns === 2 && rows === 1) return 'horizontal-first';
    return 'none';
}

/**
 * Outline of the whole arrow (shaft and head) as ONE closed polygon, so there is no overlap to double up under
 * the group's opacity. Built in a frame (t, s) anchored on `from`: `t` runs along the first leg, `s` along the
 * second (perpendicular) one, or across the shaft for a straight arrow. The tip lands exactly on `to`.
 */
function arrowPath(from: Point, to: Point, bend: 'none' | 'vertical-first' | 'horizontal-first'): string {
    let first: Point; // unit vector of the first leg
    let second: Point; // unit vector of the second leg (or across the shaft)
    let firstLength: number;
    let secondLength: number;

    if (bend === 'none') {
        const length = Math.hypot(to.x - from.x, to.y - from.y);
        first = {x: (to.x - from.x) / length, y: (to.y - from.y) / length};
        second = {x: -first.y, y: first.x};
        firstLength = length;
        secondLength = 0;
    } else {
        const corner: Point = bend === 'vertical-first' ? {x: from.x, y: to.y} : {x: to.x, y: from.y};
        firstLength = Math.hypot(corner.x - from.x, corner.y - from.y);
        secondLength = Math.hypot(to.x - corner.x, to.y - corner.y);
        first = {x: (corner.x - from.x) / firstLength, y: (corner.y - from.y) / firstLength};
        second = {x: (to.x - corner.x) / secondLength, y: (to.y - corner.y) / secondLength};
    }

    // Start on an ellipse concentric with the origin tile, covering START_ELLIPSE_RATIO of it (1 = tangent to the edges).
    const radiusX = TILE_HALF_WIDTH * START_ELLIPSE_RATIO;
    const radiusY = TILE_HALF_HEIGHT * START_ELLIPSE_RATIO;
    const offset = 1 / Math.hypot(first.x / radiusX, first.y / radiusY);
    from = {x: from.x + offset * first.x, y: from.y + offset * first.y};
    firstLength -= offset;

    const at = (t: number, s: number): string => {
        const x = from.x + t * first.x + s * second.x;
        const y = from.y + t * first.y + s * second.y;
        return `${+x.toFixed(2)} ${+y.toFixed(2)}`;
    };
    const w = SHAFT_HALF_WIDTH;
    const h = HEAD_HALF_WIDTH;

    let points: string[];
    if (bend === 'none') {
        const base = firstLength - HEAD_LENGTH; // the head never reaches back past the shaft's start: a leg is ≥ 80 long
        points = [at(0, -w), at(base, -w), at(base, -h), at(firstLength, 0), at(base, h), at(base, w), at(0, w)];
    } else {
        const base = secondLength - HEAD_LENGTH;
        const t = firstLength;
        points = [
            at(0, -w), at(t + w, -w), // along the outside of the first leg, round the outer corner
            at(t + w, base), at(t + h, base), at(t, secondLength), at(t - h, base), at(t - w, base), // up the second leg, round the head
            at(t - w, w), at(0, w), // back along the inside of the corner
        ];
    }
    return `M${points.join('L')}Z`;
}

export default class BoardArrows {
    private readonly host: BoardArrowsHost;
    private arrows: BoardArrow[] = [];
    private drawing: {from: number; to: number | null; color: ArrowColor} | null = null;

    private readonly onMouseDown = (event: MouseEvent): void => this.handleMouseDown(event);
    private readonly onContextMenu = (event: MouseEvent): void => event.preventDefault();
    private readonly onWindowMouseMove = (event: MouseEvent): void => this.handleWindowMouseMove(event);
    private readonly onWindowMouseUp = (event: MouseEvent): void => this.handleWindowMouseUp(event);
    private readonly onWindowMouseDown = (event: MouseEvent): void => this.handleWindowMouseDown(event);
    private readonly onWindowKey = (event: KeyboardEvent): void => this.handleWindowKey(event);
    private readonly onWindowBlur = (): void => this.cancelDrawing();

    constructor(host: BoardArrowsHost) {
        this.host = host;
        host.svg.addEventListener('mousedown', this.onMouseDown);
        host.svg.addEventListener('contextmenu', this.onContextMenu);
    }

    get(): BoardArrow[] {
        return this.arrows.map(({from, to, color}) => ({from, to, color}));
    }

    /** Replaces every arrow; invalid entries are dropped and a later arrow with the same from/to replaces an earlier one. */
    set(arrows: BoardArrow[]): void {
        this.arrows = [];
        for (const arrow of arrows) {
            if (isValidArrow(arrow)) {
                this.upsert(arrow);
            }
        }
        this.redraw();
    }

    /** Removes every arrow and abandons the one being drawn. */
    clear(): void {
        this.cancelDrawing();
        if (this.arrows.length === 0) return;
        this.arrows = [];
        this.redraw();
    }

    /** Draws the arrows again at the current orientation of the board (the arrows themselves are orientation-free). */
    redraw(): void {
        const layer = this.host.layer;
        while (layer.firstChild) {
            layer.removeChild(layer.firstChild);
        }
        for (const arrow of this.arrows) {
            layer.appendChild(this.createArrowElement(arrow, false));
        }
        if (this.drawing && this.drawing.to !== null) {
            layer.appendChild(this.createArrowElement({from: this.drawing.from, to: this.drawing.to, color: this.drawing.color}, true));
        }
    }

    dispose(): void {
        this.cancelDrawing();
        this.host.svg.removeEventListener('mousedown', this.onMouseDown);
        this.host.svg.removeEventListener('contextmenu', this.onContextMenu);
        this.arrows = [];
    }

    private createArrowElement(arrow: BoardArrow, preview: boolean): SVGGElement {
        const group = document.createElementNS(SVG_NS, 'g');
        group.setAttribute('class', `arrow arrow-${arrow.color}${preview ? ' arrow-preview' : ''}`);
        group.dataset.from = String(arrow.from);
        group.dataset.to = String(arrow.to);
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', arrowPath(this.host.tileCentre(arrow.from), this.host.tileCentre(arrow.to), bendOf(arrow.from, arrow.to)));
        group.appendChild(path);
        return group;
    }

    /** One arrow per from/to pair: the same colour toggles it off, another colour replaces it. */
    private toggle(arrow: BoardArrow): void {
        const index = this.arrows.findIndex((existing) => existing.from === arrow.from && existing.to === arrow.to);
        if (index >= 0 && this.arrows[index].color === arrow.color) {
            this.arrows.splice(index, 1);
        } else {
            this.upsert(arrow);
        }
        this.redraw();
    }

    private upsert(arrow: BoardArrow): void {
        const index = this.arrows.findIndex((existing) => existing.from === arrow.from && existing.to === arrow.to);
        const record = {from: arrow.from, to: arrow.to, color: arrow.color};
        if (index >= 0) {
            this.arrows[index] = record;
        } else {
            this.arrows.push(record);
        }
    }

    private handleMouseDown(event: MouseEvent): void {
        if (event.button !== 2 || !this.host.canStart()) return;
        const from = this.host.tileAt(event);
        if (from === null) return;

        this.cancelDrawing();
        this.drawing = {from, to: null, color: colorForModifiers(event)};
        window.addEventListener('mousemove', this.onWindowMouseMove);
        window.addEventListener('mouseup', this.onWindowMouseUp);
        window.addEventListener('mousedown', this.onWindowMouseDown, true);
        window.addEventListener('keydown', this.onWindowKey);
        window.addEventListener('keyup', this.onWindowKey);
        window.addEventListener('blur', this.onWindowBlur);
    }

    private handleWindowMouseMove(event: MouseEvent): void {
        if (!this.drawing) return;
        if ((event.buttons & 2) === 0) { // the release happened where we could not see it
            this.cancelDrawing();
            return;
        }
        const color = colorForModifiers(event);
        const tile = this.host.tileAt(event);
        const to = tile === this.drawing.from ? null : tile;
        if (to === this.drawing.to && color === this.drawing.color) return;
        this.drawing.color = color;
        this.drawing.to = to;
        this.redraw();
    }

    private handleWindowMouseUp(event: MouseEvent): void {
        if (event.button !== 2 || !this.drawing) return;
        const {from} = this.drawing;
        const to = this.host.tileAt(event);
        const color = colorForModifiers(event);
        const releasedOutside = !(event.target instanceof Node && this.host.svg.contains(event.target));

        this.cancelDrawing();
        if (releasedOutside) {
            this.swallowNextContextMenu();
        }
        if (to !== null && to !== from) {
            this.toggle({from, to, color});
        }
    }

    /** A press of the left button while drawing abandons the arrow (the press itself carries on as usual). */
    private handleWindowMouseDown(event: MouseEvent): void {
        if (event.button === 0) {
            this.cancelDrawing();
        }
    }

    private handleWindowKey(event: KeyboardEvent): void {
        if (!this.drawing) return;
        if (event.type === 'keydown' && event.key === 'Escape') {
            this.cancelDrawing();
            return;
        }
        // Shift / Ctrl pressed or released while the mouse rests: the preview follows the colour that a release would give
        this.drawing.color = colorForModifiers(event);
        this.redraw();
    }

    private cancelDrawing(): void {
        if (!this.drawing) return;
        this.drawing = null;
        window.removeEventListener('mousemove', this.onWindowMouseMove);
        window.removeEventListener('mouseup', this.onWindowMouseUp);
        window.removeEventListener('mousedown', this.onWindowMouseDown, true);
        window.removeEventListener('keydown', this.onWindowKey);
        window.removeEventListener('keyup', this.onWindowKey);
        window.removeEventListener('blur', this.onWindowBlur);
        this.redraw();
    }

    /** Releasing the right button off the board must not pop the browser menu up there either. */
    private swallowNextContextMenu(): void {
        const swallow = (event: Event): void => event.preventDefault();
        window.addEventListener('contextmenu', swallow, {capture: true, once: true});
        setTimeout(() => window.removeEventListener('contextmenu', swallow, true), CONTEXT_MENU_GRACE_MS);
    }
}
