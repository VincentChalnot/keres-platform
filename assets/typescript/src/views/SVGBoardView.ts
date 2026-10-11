import {IBoardView, TileHighlight} from './IBoardView';
import {AnimationSpeed, BOARD_SIZE, LAST_BOARD_INDEX} from '../models/types';
import {GameState} from '../models/GameState';
import {decodePiece} from "../utils/boardUtils";
import {injectBoardCss} from './boardCss';
import {buildBoardHtml} from './boardExport';
import BoardArrows, {BoardArrow} from './BoardArrows';

// Constants for the SVG board
const SQUARE_WIDTH = 100; // px
const SQUARE_HEIGHT = 80; // px
const BOARD_WIDTH = BOARD_SIZE * SQUARE_WIDTH; // 900px
const BOARD_HEIGHT = BOARD_SIZE * SQUARE_HEIGHT; // 720px
const STACKED_OFFSET = 20;

// Coordinate label dimensions (left margin for row labels, bottom margin for column labels)
const COORD_WIDTH = 25; // px left margin for row numbers (1-9)
const COORD_HEIGHT = 25; // px bottom margin for column letters (A-I)
const TOP_MARGIN = 10;

const SPRITE_URL = '/build/pieces-sprite.svg';

interface HighlightStyle {
    fill: string;
    opacity: string;
}

const HIGHLIGHT_STYLES: Record<TileHighlight['type'], HighlightStyle> = {
    selected: {fill: '#7fa0dd', opacity: '0.6'},
    potential: {fill: '#55d157', opacity: '0.5'},
    hovered: {fill: '#e1ca58', opacity: '0.4'},
    threat: {fill: '#ff4444', opacity: '0.5'},
    last_move: {fill: '#e89038', opacity: '0.5'},
};

/**
 * SVG-based board renderer
 */
export default class SVGBoardView implements IBoardView {
    private container!: HTMLElement;
    private svg!: SVGSVGElement;
    private boardGroup!: SVGGElement;
    private piecesGroup!: SVGGElement;
    private overlaysGroup!: SVGGElement;
    private coordsGroup!: SVGGElement;
    private arrowsGroup!: SVGGElement;
    // Right-button analysis arrows: purely visual state of this view, cleared by whoever changes the displayed position
    private arrows: BoardArrows | null = null;
    private gameState: GameState;

    private clickHandler: ((tileIndex: number, shiftKey?: boolean) => void) | null = null;
    private hoverHandler: ((tileIndex: number | null) => void) | null = null;
    private dragMoveHandler: ((from: number, to: number, shiftKey?: boolean) => void) | null = null;
    private pieceLongPressHandler: ((tileIndex: number) => void) | null = null;

    // Track current board state to enable differential updates
    private currentBoardData: Uint8Array | null = null;
    private currentFlipped: boolean = false;

    // Cache for overlay rectangles
    private overlayElements: Map<number, SVGRectElement> = new Map();

    // What the overlays currently show, in painting order (a later highlight of a tile covers an earlier one)
    private painted: Array<{visualIndex: number; type: TileHighlight['type']}> = [];

    // Drag & drop state
    private dragState: {
        active: boolean;
        from: number;
        startX: number;
        startY: number;
        ghost: SVGGElement | null;
    } = { active: false, from: -1, startX: 0, startY: 0, ghost: null };
    private preventNextClick: boolean = false;
    private static readonly DRAG_THRESHOLD = 5; // pixels

    // Long press state — only triggered by sustained mousedown/touchstart, NOT hover
    private longPressTimer: ReturnType<typeof setTimeout> | null = null;
    private static readonly LONG_PRESS_DELAY = 800; // ms

    // Set when a long press fired: the click (mouse) or touchend that ends the press must not also select the tile
    private longPressFired: boolean = false;

    // Whether coordinate labels are visible (affects viewBox)
    private coordsVisible: boolean = true;

    // Whether the opponent's piece icons are drawn upside down (off by default)
    private opponentRotated: boolean = false;

    // How pieces travel to their square (board.css owns the durations); instant until the page says otherwise
    private animationSpeed: AnimationSpeed = 'instant';

    // The move the user just made by drag & drop: the ghost already showed it, so the piece must not travel again
    private droppedMove: {from: number; to: number} | null = null;

    // Bound event handler references for proper cleanup
    private boundHandleClick: ((e: MouseEvent) => void) | null = null;
    private boundHandleMouseMove: ((e: MouseEvent) => void) | null = null;
    private boundHandleMouseLeave: (() => void) | null = null;
    private boundHandleMouseDown: ((e: MouseEvent) => void) | null = null;
    private boundHandleMouseUp: ((e: MouseEvent) => void) | null = null;
    private boundHandleWindowMouseUp: ((e: MouseEvent) => void) | null = null;
    private boundHandleTouchStart: ((e: TouchEvent) => void) | null = null;
    private boundHandleTouchMove: ((e: TouchEvent) => void) | null = null;
    private boundHandleTouchEnd: ((e: TouchEvent) => void) | null = null;
    private boundOnResize: (() => void) | null = null;

    constructor(gameState: GameState) {
        this.gameState = gameState;
    }

    async initialize(container: HTMLElement): Promise<void> {
        this.container = container;
        injectBoardCss();
        this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.applyViewBox();
        this.svg.classList.add('keres-board');
        this.svg.setAttribute('data-anim-speed', this.animationSpeed);

        // Inline the sprite sheet symbols/defs directly into the SVG
        await this.inlineSpriteDefs(this.svg);

        // Create groups for layering as in board.svg
        this.boardGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        this.boardGroup.setAttribute('id', 'board-layer');
        this.svg.appendChild(this.boardGroup);

        this.coordsGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        this.coordsGroup.setAttribute('id', 'coords-layer');
        this.svg.appendChild(this.coordsGroup);

        this.overlaysGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        this.overlaysGroup.setAttribute('id', 'overlays-layer');
        this.svg.appendChild(this.overlaysGroup);

        this.piecesGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        this.piecesGroup.setAttribute('id', 'pieces-layer');
        this.svg.appendChild(this.piecesGroup);

        // Analysis arrows live above the pieces (and never take the mouse: board.css)
        this.arrowsGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        this.arrowsGroup.setAttribute('id', 'arrows-layer');
        this.svg.appendChild(this.arrowsGroup);

        container.appendChild(this.svg);

        // Create the board background
        this.createBoard();
        this.createCoordinates(false);
        this.createOverlays();
        this.arrows = new BoardArrows({
            svg: this.svg,
            layer: this.arrowsGroup,
            tileCentre: (index) => {
                const {x, y} = this.getTilePosition(index);
                return {x: x + SQUARE_WIDTH / 2, y: y + SQUARE_HEIGHT / 2};
            },
            tileAt: (event) => this.getPosFromMouseEvent(event),
            // A piece in the air owns the mouse; a merely pressed left button does not
            canStart: () => !this.dragState.active,
        });

        // Event listeners - bind and store references for proper cleanup
        this.boundHandleClick = (e: MouseEvent) => this.handleClick(e);
        this.boundHandleMouseMove = (e: MouseEvent) => this.handleMouseMove(e);
        this.boundHandleMouseLeave = () => this.handleMouseLeave();
        this.boundHandleMouseDown = (e: MouseEvent) => this.handleMouseDown(e);
        this.boundHandleMouseUp = (e: MouseEvent) => this.handleMouseUp(e);
        this.boundHandleTouchStart = (e: TouchEvent) => this.handleTouchStart(e);
        this.boundHandleTouchMove = (e: TouchEvent) => this.handleTouchMove(e);
        this.boundHandleTouchEnd = (e: TouchEvent) => this.handleTouchEnd(e);
        this.boundOnResize = () => this.onResize();
        this.boundHandleWindowMouseUp = (e: MouseEvent) => this.handleWindowMouseUp(e);

        this.svg.addEventListener('click', this.boundHandleClick);
        this.svg.addEventListener('mousemove', this.boundHandleMouseMove);
        this.svg.addEventListener('mouseleave', this.boundHandleMouseLeave);
        this.svg.addEventListener('mousedown', this.boundHandleMouseDown);
        this.svg.addEventListener('mouseup', this.boundHandleMouseUp);
        this.svg.addEventListener('touchstart', this.boundHandleTouchStart, { passive: false });
        this.svg.addEventListener('touchmove', this.boundHandleTouchMove, { passive: false });
        this.svg.addEventListener('touchend', this.boundHandleTouchEnd);

        window.addEventListener('resize', this.boundOnResize);
        // The board never sees a button released outside it: a drag would stay in the air (ghost shown, piece hidden)
        window.addEventListener('mouseup', this.boundHandleWindowMouseUp);
        this.onResize();
    }

    private async inlineSpriteDefs(targetSvg: SVGSVGElement) {
        // Fetch the sprite SVG and insert its <defs> or <symbol> content into the main SVG
        const response = await fetch(SPRITE_URL);
        const svgText = await response.text();
        const parser = new DOMParser();
        const spriteDoc = parser.parseFromString(svgText, 'image/svg+xml');
        // Move all <defs> and <symbol> children into our SVG
        const spriteSvg = spriteDoc.documentElement;
        for (const child of Array.from(spriteSvg.children)) {
            if (child.tagName === 'defs' || child.tagName === 'symbol') {
                targetSvg.appendChild(targetSvg.ownerDocument.importNode(child, true));
            }
        }
    }

    private createBoard(): void {
        // Use <use> elements to build the board as in board.svg
        // 9 rows, alternating odd/even
        for (let row = 0; row < BOARD_SIZE; row++) {
            const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            use.setAttribute('href', row % 2 === 0 ? '#board-row-odd' : '#board-row-even');
            use.setAttribute('y', String(row * SQUARE_HEIGHT));
            this.boardGroup.appendChild(use);
        }
    }

    private createCoordinates(flipped: boolean): void {
        // Clear existing coordinate labels
        while (this.coordsGroup.firstChild) {
            this.coordsGroup.removeChild(this.coordsGroup.firstChild);
        }

        const columns = flipped
            ? ['I', 'H', 'G', 'F', 'E', 'D', 'C', 'B', 'A']
            : ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

        // Row labels (1-9) on the left side
        for (let row = 0; row < BOARD_SIZE; row++) {
            const rowNumber = flipped ? (row + 1) : (BOARD_SIZE - row);
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', String(-COORD_WIDTH / 2));
            text.setAttribute('y', String(row * SQUARE_HEIGHT + SQUARE_HEIGHT / 2));
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'central');
            text.setAttribute('class', 'coord-label');
            text.textContent = String(rowNumber);
            this.coordsGroup.appendChild(text);
        }

        // Column labels (A-I) at the bottom
        for (let col = 0; col < BOARD_SIZE; col++) {
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', String(col * SQUARE_WIDTH + SQUARE_WIDTH / 2));
            text.setAttribute('y', String(BOARD_HEIGHT + COORD_HEIGHT / 2));
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'central');
            text.setAttribute('class', 'coord-label');
            text.textContent = columns[col];
            this.coordsGroup.appendChild(text);
        }
    }

    private createOverlays(): void {
        // Create invisible overlay rectangles for each square
        for (let i = 0; i <= LAST_BOARD_INDEX; i++) {
            const {x, y} = this.getTilePosition(i);
            
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', x.toString());
            rect.setAttribute('y', y.toString());
            rect.setAttribute('width', SQUARE_WIDTH.toString());
            rect.setAttribute('height', SQUARE_HEIGHT.toString());
            rect.setAttribute('fill', 'transparent');
            rect.setAttribute('opacity', '0');
            rect.setAttribute('pointer-events', 'all');
            rect.dataset.tileIndex = i.toString();
            
            this.overlaysGroup.appendChild(rect);
            this.overlayElements.set(i, rect);
        }
    }

    private getTilePosition(index: number): {x: number, y: number} {
        const actualIndex = this.gameState.isBoardFlipped() ? (LAST_BOARD_INDEX - index) : index;
        const col = actualIndex % BOARD_SIZE;
        const row = Math.floor(actualIndex / BOARD_SIZE);

        return {
            x: col * SQUARE_WIDTH,
            y: row * SQUARE_HEIGHT
        };
    }

    private getTileIndex(x: number, y: number): number | null {
        // Given board coordinates (x, y), return the tile index, respecting board flip
        const col = Math.floor(x / SQUARE_WIDTH);
        const row = Math.floor(y / SQUARE_HEIGHT);
        if (col < 0 || col >= BOARD_SIZE || row < 0 || row >= BOARD_SIZE) {
            return null;
        }
        const visualIndex = row * BOARD_SIZE + col;
        const flipped = this.gameState.isBoardFlipped();
        return flipped ? (LAST_BOARD_INDEX - visualIndex) : visualIndex;
    }

    async render(boardData: Uint8Array, flipped: boolean): Promise<void> {
        // Check if board orientation changed
        const orientationChanged = this.currentFlipped !== flipped;

        // A capture that is still fading out belongs to the previous position
        this.discardCapturedPieces();

        // If this is the first render or orientation changed, recreate all pieces (never animated)
        if (!this.currentBoardData || orientationChanged) {
            this.recreateAllPieces(boardData, flipped);
            if (orientationChanged) {
                this.createCoordinates(flipped);
                this.arrows?.redraw(); // the arrows stay on their squares, which moved
            }
            this.currentBoardData = new Uint8Array(boardData);
            this.currentFlipped = flipped;
            return;
        }

        // Perform differential update
        const changes: number[] = [];
        for (let i = 0; i <= LAST_BOARD_INDEX; i++) {
            if (boardData[i] !== this.currentBoardData[i]) {
                changes.push(i);
            }
        }
        if (changes.length === 0) return;

        const droppedMove = this.droppedMove;
        this.droppedMove = null;

        // One ply: the moving pieces slide (CSS transition) while the others stay.
        // Anything else (jump over several plies, loading a game): redraw the changed tiles at once.
        if (!this.slidePieces(this.currentBoardData, boardData, changes, flipped, droppedMove)) {
            for (const index of changes) {
                this.updatePieceAtIndex(index, boardData[index], flipped);
            }
        }

        // Update cached board state
        this.currentBoardData = new Uint8Array(boardData);
    }

    /**
     * Resolves when the piece movement (and capture fade) started by the last render is over.
     * Only observes the CSS transitions/animations - nothing here drives them.
     */
    async whenSettled(): Promise<void> {
        const running = this.piecesGroup?.getAnimations({subtree: true}) ?? [];
        await Promise.allSettled(running.map(animation => animation.finished));
    }

    setAnimationSpeed(speed: AnimationSpeed): void {
        this.animationSpeed = speed;
        // board.css maps the attribute to --piece-move-duration
        this.svg?.setAttribute('data-anim-speed', speed);
    }

    private recreateAllPieces(boardData: Uint8Array, flipped: boolean): void {
        // Clear existing pieces
        while (this.piecesGroup.firstChild) {
            this.piecesGroup.removeChild(this.piecesGroup.firstChild);
        }

        // Create all pieces
        for (let i = 0; i <= LAST_BOARD_INDEX; i++) {
            const pieceVal = boardData[i];
            if (pieceVal === 0) continue;

            this.updatePieceAtIndex(i, pieceVal, flipped);
        }
    }

    /**
     * Pieces are positioned with a CSS transform (x/y attributes of <use> cannot be transitioned);
     * board.css animates it. A piece element keeps its tile/role in data attributes.
     */
    private placePiece(use: SVGElement, tileIndex: number, isTopPiece: boolean): void {
        const {x, y} = this.getTilePosition(tileIndex);
        use.style.transform = `translate(${x}px, ${y - (isTopPiece ? STACKED_OFFSET : 0)}px)`;
        use.dataset.tileIndex = tileIndex.toString();
        use.dataset.isTopPiece = isTopPiece.toString();
    }

    private createPieceUse(pieceType: string, color: boolean, reversed: boolean, tileIndex: number, isTopPiece: boolean): SVGUseElement {
        // Use <use> referencing the inlined sprite symbol
        const colorClass = color ? 'p-w' : 'p-b';
        const reversedClass = this.opponentRotated && color === reversed ? 'p-r' : '';
        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', `#piece-${pieceType}`);
        use.setAttribute('class', `piece ${colorClass} ${reversedClass}`);
        this.placePiece(use, tileIndex, isTopPiece);
        this.piecesGroup.appendChild(use);
        return use;
    }

    private updatePieceAtIndex(index: number, pieceVal: number, flipped: boolean): void {
        // Remove existing pieces at this position
        this.removePiecesAtIndex(index);

        // Add new piece if there's a piece
        const piece = decodePiece(pieceVal);
        if (!piece) return;

        // Render bottom piece
        this.createPieceUse(piece.bottom, piece.color, flipped, index, false);

        // Render top piece if stacked
        if (piece.top) {
            this.createPieceUse(piece.top, piece.color, flipped, index, true);
        }
    }

    private removePiecesAtIndex(index: number): void {
        // Find and remove all piece elements at this tile index
        const pieces = this.piecesGroup.querySelectorAll(`[data-tile-index="${index}"]`);
        pieces.forEach(piece => piece.remove());
    }

    private discardCapturedPieces(): void {
        this.piecesGroup?.querySelectorAll('.piece-captured').forEach(piece => piece.remove());
    }

    /**
     * Applies a one-ply change of the position by moving the existing piece elements, so that the
     * CSS transition on `transform` slides them to their new square.
     *
     * Elements are matched between the old and the new position per changed tile: the piece that stays
     * on its square (same colour/type/role) is kept; the others are paired by colour + type, preferring
     * the same stack role; a leftover of the same colour is the mover that promoted (type changes on
     * arrival). Old elements nobody claims are captured (faded out), new pieces nobody claims are
     * revived by an undo (faded in on the square the mover leaves).
     *
     * Returns false - nothing touched - when the change is not one piece/stack travelling from one
     * square to another (first/last jump, loading a game...): the caller redraws instead.
     */
    private slidePieces(previous: Uint8Array, next: Uint8Array, changes: number[], flipped: boolean, droppedMove: {from: number; to: number} | null): boolean {
        if (changes.length > 2) return false;

        interface Slot {
            tile: number;
            type: string;
            color: boolean;
            top: boolean;
            el: SVGUseElement | null;
            partner: Slot | null;
        }
        const olds: Slot[] = [];
        const news: Slot[] = [];
        for (const tile of changes) {
            for (const [board, slots] of [[previous, olds], [next, news]] as const) {
                const piece = decodePiece(board[tile]);
                if (!piece) continue;
                const parts: Array<[string, boolean]> = [[piece.bottom, false]];
                if (piece.top) parts.push([piece.top, true]);
                for (const [type, top] of parts) {
                    slots.push({tile, type, color: piece.color, top, el: null, partner: null});
                }
            }
        }
        for (const old of olds) {
            old.el = this.piecesGroup.querySelector<SVGUseElement>(`.piece[data-tile-index="${old.tile}"][data-is-top-piece="${old.top}"]`);
            if (!old.el) return false;
        }

        const passes: Array<(o: Slot, n: Slot) => boolean> = [
            (o, n) => o.tile === n.tile && o.type === n.type && o.color === n.color && o.top === n.top,
            (o, n) => o.tile === n.tile && o.type === n.type && o.color === n.color,
            (o, n) => o.tile !== n.tile && o.type === n.type && o.color === n.color && o.top === n.top,
            (o, n) => o.tile !== n.tile && o.type === n.type && o.color === n.color,
            (o, n) => o.color === n.color,
        ];
        for (const matches of passes) {
            for (const old of olds) {
                if (old.partner) continue;
                const candidate = news.find(n => !n.partner && matches(old, n));
                if (candidate) {
                    old.partner = candidate;
                    candidate.partner = old;
                }
            }
        }

        const moved = olds.filter(o => o.partner && o.partner.tile !== o.tile);
        if (moved.length === 0) return false;
        const source = moved[0].tile;
        const destination = moved[0].partner!.tile;
        if (!moved.every(o => o.tile === source && o.partner!.tile === destination)) return false;
        const captured = olds.filter(o => !o.partner);
        const revived = news.filter(n => !n.partner);
        if (captured.some(o => o.tile !== destination) || revived.some(n => n.tile !== source)) return false;

        // The piece the user just dragged is already on its square (the drag ghost): no second trip
        const animate = this.animationSpeed !== 'instant' && !(droppedMove && droppedMove.from === source && droppedMove.to === destination);

        for (const old of captured) {
            const el = old.el!;
            delete el.dataset.tileIndex;
            if (animate) {
                el.classList.add('piece-captured');
                el.addEventListener('animationend', () => el.remove(), {once: true});
            } else {
                el.remove();
            }
        }
        for (const slot of revived) {
            const el = this.createPieceUse(slot.type, slot.color, flipped, slot.tile, slot.top);
            if (animate) {
                el.classList.add('piece-revived');
                el.addEventListener('animationend', () => el.classList.remove('piece-revived'), {once: true});
            }
        }
        for (const old of olds) {
            const slot = old.partner;
            if (!slot || slot.tile !== old.tile) continue;
            // Stays on its square (it may still change role)
            this.placePiece(old.el!, slot.tile, slot.top);
        }

        // Moving pieces are drawn above the static ones, bottom of a stack first. Re-inserting a node cancels
        // its running transition, so keep its current look as the starting point and let the browser
        // compute that style before the new transform is set.
        moved.sort((a, b) => Number(a.partner!.top) - Number(b.partner!.top));
        for (const old of moved) {
            const el = old.el!;
            const current = getComputedStyle(el).transform;
            this.piecesGroup.appendChild(el);
            if (current && current !== 'none') el.style.transform = current;
        }
        for (const old of moved) {
            void getComputedStyle(old.el!).transform;
        }
        for (const old of moved) {
            const el = old.el!;
            const slot = old.partner!;
            if (slot.type !== old.type) el.setAttribute('href', `#piece-${slot.type}`);
            el.style.visibility = ''; // a piece hidden for a drag that this move interrupted must not stay invisible
            if (!animate) el.style.transition = 'none';
            this.placePiece(el, slot.tile, slot.top);
            if (!animate) {
                void getComputedStyle(el).transform;
                el.style.transition = '';
            }
        }

        return true;
    }

    updateOverlays(highlights: TileHighlight[]): void {
        const flipped = this.gameState.isBoardFlipped();

        // Reset all overlays
        for (let i = 0; i <= LAST_BOARD_INDEX; i++) {
            const overlay = this.overlayElements.get(i);
            if (overlay) {
                overlay.setAttribute('opacity', '0');
                overlay.setAttribute('fill', 'transparent');
            }
        }

        // Apply highlights
        this.painted = [];
        for (const highlight of highlights) {
            const tileIndex = highlight.position;
            const visualIndex = flipped ? (LAST_BOARD_INDEX - tileIndex) : tileIndex;
            this.painted.push({visualIndex, type: highlight.type});
            const overlay = this.overlayElements.get(visualIndex);
            if (!overlay) continue;

            const style = HIGHLIGHT_STYLES[highlight.type];
            overlay.setAttribute('fill', style.fill);
            overlay.setAttribute('opacity', style.opacity);
        }
    }

    onResize(): void {
        // The SVG scales automatically with CSS, but we can adjust container if needed
        // For now, the viewBox handles scaling
    }

    private handleClick(event: MouseEvent): void {
        // The press that ended a long press (piece inspection) is not also a click
        if (this.longPressFired) {
            this.longPressFired = false;
            this.preventNextClick = false;
            return;
        }

        if (this.preventNextClick) {
            this.preventNextClick = false;
            return;
        }
        if (!this.clickHandler) return;

        const pos = this.getPosFromMouseEvent(event);
        if (pos !== null) {
            this.clickHandler(pos, event.shiftKey);
        }
    }

    private handleMouseDown(event: MouseEvent): void {
        if (event.button !== 0) return;
        const pos = this.getPosFromMouseEvent(event);
        if (pos === null) return;
        this.droppedMove = null;
        this.dragState.from = pos;
        this.dragState.startX = event.clientX;
        this.dragState.startY = event.clientY;
        this.dragState.active = false;
        // Start long press timer on mouse down
        this.startLongPressTimer(pos);
    }

    private handleMouseMove(event: MouseEvent): void {
        // Handle drag in progress
        if (this.dragState.from >= 0) {
            const dx = event.clientX - this.dragState.startX;
            const dy = event.clientY - this.dragState.startY;
            if (!this.dragState.active && (dx * dx + dy * dy) > SVGBoardView.DRAG_THRESHOLD * SVGBoardView.DRAG_THRESHOLD) {
                this.cancelLongPressTimer();
                this.startDrag(this.dragState.from);
            }
            if (this.dragState.active && this.dragState.ghost) {
                this.updateDragGhost(event.clientX, event.clientY);
            }
            return;
        }

        // Normal hover
        if (!this.hoverHandler) return;
        const pos = this.getPosFromMouseEvent(event);
        if (pos !== null) {
            this.hoverHandler(pos);
        } else {
            this.hoverHandler(null);
        }
    }

    private handleMouseUp(event: MouseEvent): void {
        // Only the primary button drops a piece (the right one belongs to the arrows)
        if (event.button !== 0) return;
        this.cancelLongPressTimer();
        if (!this.dragState.active) {
            this.dragState.from = -1;
            return;
        }
        const to = this.getPosFromMouseEvent(event);
        this.endDrag(to, event.shiftKey);
    }

    /** The primary button went up somewhere the board did not see (handleMouseUp clears `from`): an active drag is dropped nowhere. */
    private handleWindowMouseUp(event: MouseEvent): void {
        if (event.button !== 0 || this.dragState.from < 0) return;
        this.cancelLongPressTimer();
        if (this.dragState.active) {
            this.endDrag(null);
            this.preventNextClick = false; // the click of this press went to something outside the board: none is left to swallow
        } else {
            this.dragState.from = -1;
        }
    }

    private handleTouchStart(event: TouchEvent): void {
        if (event.touches.length !== 1) return;
        const touch = event.touches[0];
        const pos = this.getPosFromTouch(touch);
        if (pos === null) return;
        this.droppedMove = null;
        this.dragState.from = pos;
        this.dragState.startX = touch.clientX;
        this.dragState.startY = touch.clientY;
        this.dragState.active = false;
        // Start long press timer for touch
        this.startLongPressTimer(pos);
    }

    private handleTouchMove(event: TouchEvent): void {
        if (this.dragState.from < 0 || event.touches.length !== 1) return;
        const touch = event.touches[0];
        const dx = touch.clientX - this.dragState.startX;
        const dy = touch.clientY - this.dragState.startY;
        if (!this.dragState.active && (dx * dx + dy * dy) > SVGBoardView.DRAG_THRESHOLD * SVGBoardView.DRAG_THRESHOLD) {
            this.cancelLongPressTimer();
            this.startDrag(this.dragState.from);
        }
        if (this.dragState.active && this.dragState.ghost) {
            event.preventDefault();
            this.updateDragGhost(touch.clientX, touch.clientY);
        }
    }

    private handleTouchEnd(event: TouchEvent): void {
        this.cancelLongPressTimer();
        if (!this.dragState.active) {
            // A long press only inspects the piece: swallow the synthetic click that would follow
            if (this.longPressFired) {
                this.longPressFired = false;
                event.preventDefault();
                return;
            }
            this.dragState.from = -1;
            return;
        }
        // Use the last known touch position from changedTouches
        const touch = event.changedTouches[0];
        const to = this.getPosFromTouch(touch);
        this.endDrag(to, event.shiftKey);
    }

    private startDrag(from: number): void {
        this.dragState.active = true;

        // Hide the source piece(s) so only the ghost is visible
        const sourcePieces = this.piecesGroup.querySelectorAll(`[data-tile-index="${from}"]`);
        sourcePieces.forEach(el => (el as SVGElement).style.visibility = 'hidden');

        // Select the source piece to show potential move highlights
        if (this.clickHandler) {
            this.clickHandler(from);
        }

        // Create a ghost piece for visual feedback
        this.createDragGhost(from);
    }

    private endDrag(to: number | null, shiftKey: boolean = false): void {
        // Remove ghost
        if (this.dragState.ghost) {
            this.dragState.ghost.remove();
            this.dragState.ghost = null;
        }

        const from = this.dragState.from;
        this.dragState.active = false;
        this.dragState.from = -1;
        this.preventNextClick = true;

        // Restore hidden source pieces
        const sourcePieces = this.piecesGroup.querySelectorAll(`[data-tile-index="${from}"]`);
        sourcePieces.forEach(el => (el as SVGElement).style.visibility = '');

        if (to !== null && to !== from && this.dragMoveHandler) {
            this.droppedMove = {from, to};
            this.dragMoveHandler(from, to, shiftKey);
        } else if (this.clickHandler) {
            // Cancel: deselect by clicking the same position
            this.clickHandler(from);
        }
    }

    private createDragGhost(from: number): void {
        if (!this.currentBoardData) return;
        const pieceVal = this.currentBoardData[from];
        if (pieceVal === 0) return;

        const piece = decodePiece(pieceVal);
        if (!piece) return;

        const ghost = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        ghost.setAttribute('data-drag-ghost', ''); // not part of the position: left out of an export
        ghost.setAttribute('pointer-events', 'none');
        ghost.style.opacity = '0.7';

        const flipped = this.gameState.isBoardFlipped();
        const colorClass = piece.color ? 'p-w' : 'p-b';
        const reversedClass = (this.opponentRotated && piece.color === flipped) ? 'p-r' : '';

        // Render bottom piece
        const useBottom = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        useBottom.setAttribute('href', `#piece-${piece.bottom}`);
        useBottom.setAttribute('class', `piece ${colorClass} ${reversedClass}`);
        useBottom.setAttribute('x', '0');
        useBottom.setAttribute('y', '0');
        ghost.appendChild(useBottom);

        // Render top piece if stacked
        if (piece.top) {
            const useTop = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            useTop.setAttribute('href', `#piece-${piece.top}`);
            useTop.setAttribute('class', `piece ${colorClass} ${reversedClass}`);
            useTop.setAttribute('x', '0');
            useTop.setAttribute('y', (-STACKED_OFFSET).toString());
            ghost.appendChild(useTop);
        }

        this.svg.appendChild(ghost);
        this.dragState.ghost = ghost;
    }

    private updateDragGhost(clientX: number, clientY: number): void {
        if (!this.dragState.ghost) return;
        const rect = this.svg.getBoundingClientRect();
        const coordW = this.coordsVisible ? COORD_WIDTH : 0;
        const coordH = this.coordsVisible ? COORD_HEIGHT : 0;
        const totalSvgWidth = BOARD_WIDTH + coordW;
        const totalSvgHeight = BOARD_HEIGHT + coordH;
        const svgX = ((clientX - rect.left) / rect.width) * totalSvgWidth - coordW - SQUARE_WIDTH / 2;
        const svgY = ((clientY - rect.top) / rect.height) * totalSvgHeight - SQUARE_HEIGHT / 2;
        this.dragState.ghost.setAttribute('transform', `translate(${svgX}, ${svgY})`);
    }

    private getPosFromTouch(touch: Touch): number | null {
        const rect = this.svg.getBoundingClientRect();
        const x = touch.clientX - rect.left;
        const y = touch.clientY - rect.top;
        const coordW = this.coordsVisible ? COORD_WIDTH : 0;
        const coordH = this.coordsVisible ? COORD_HEIGHT : 0;
        const totalSvgWidth = BOARD_WIDTH + coordW;
        const totalSvgHeight = BOARD_HEIGHT + coordH;
        const svgX = (x / rect.width) * totalSvgWidth - coordW;
        const svgY = (y / rect.height) * totalSvgHeight;
        return this.getTileIndex(svgX, svgY);
    }

    private handleMouseLeave(): void {
        this.cancelLongPressTimer();
        if (this.hoverHandler) {
            this.hoverHandler(null);
        }
    }

    private getPosFromMouseEvent(event: MouseEvent): number | null {
        const rect = this.svg.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        // Convert from screen coordinates to SVG coordinates
        // ViewBox depends on coordsVisible
        const coordW = this.coordsVisible ? COORD_WIDTH : 0;
        const coordH = this.coordsVisible ? COORD_HEIGHT : 0;
        const totalSvgWidth = BOARD_WIDTH + coordW;
        const totalSvgHeight = BOARD_HEIGHT + coordH;
        const svgX = (x / rect.width) * totalSvgWidth - coordW;
        const svgY = (y / rect.height) * totalSvgHeight;

        return this.getTileIndex(svgX, svgY);
    }

    onTileClick(handler: (tileIndex: number, shiftKey?: boolean) => void): void {
        this.clickHandler = handler;
    }

    onTileHover(handler: (tileIndex: number | null) => void): void {
        this.hoverHandler = handler;
    }

    onDragMove(handler: (from: number, to: number, shiftKey?: boolean) => void): void {
        this.dragMoveHandler = handler;
    }

    onPieceLongPress(handler: (tileIndex: number) => void): void {
        this.pieceLongPressHandler = handler;
    }

    /**
     * Start the long-press timer. The timer fires only if the button/finger
     * is held down without moving beyond the drag threshold; it reports the
     * piece to inspect and nothing else (no selection, no drag).
     */
    private startLongPressTimer(pos: number): void {
        this.cancelLongPressTimer();
        if (!this.pieceLongPressHandler || !this.currentBoardData || this.currentBoardData[pos] === 0) return;
        this.longPressTimer = setTimeout(() => {
            this.longPressTimer = null;
            // Neutralise drag state so a subsequent mousemove/touchmove can't start a drag
            this.dragState.from = -1;
            this.dragState.active = false;
            this.longPressFired = true;
            this.pieceLongPressHandler?.(pos);
        }, SVGBoardView.LONG_PRESS_DELAY);
    }

    private cancelLongPressTimer(): void {
        if (this.longPressTimer !== null) {
            clearTimeout(this.longPressTimer);
            this.longPressTimer = null;
        }
    }

    setOpponentRotated(rotated: boolean): void {
        this.opponentRotated = rotated;
        const flipped = this.gameState.isBoardFlipped();
        this.piecesGroup?.querySelectorAll('.piece').forEach((use) => {
            const white = use.classList.contains('p-w');
            use.classList.toggle('p-r', rotated && white === flipped);
        });
    }

    setCoordinatesVisible(visible: boolean): void {
        this.coordsVisible = visible;
        this.applyViewBox();
    }

    /** Removes every analysis arrow. */
    clearArrows(): void {
        this.arrows?.clear();
    }

    getArrows(): BoardArrow[] {
        return this.arrows?.get() ?? [];
    }

    /** Replaces the analysis arrows (invalid ones are dropped; one arrow per from/to pair, the last wins). */
    setArrows(arrows: BoardArrow[]): void {
        this.arrows?.set(arrows);
    }

    /**
     * The complete HTML document of the board as it is displayed right now (see `boardExport.ts`): the position at the
     * displayed ply, orientation, coordinates, piece rotation, arrows and the painted overlay (selection, potential
     * moves, last move, threats - not a mouse hover). Works on a copy: the live board is never touched.
     */
    exportStaticHtml(title: string): string {
        return buildBoardHtml(this.svg, {title, prepare: (clone) => this.freezeForExport(clone)});
    }

    /** What is specific to this view in the copy to export: no drag in the air, only the lasting highlights. */
    private freezeForExport(clone: SVGSVGElement): void {
        clone.querySelectorAll('[data-drag-ghost]').forEach((ghost) => ghost.remove());

        // A hover is not part of the position; where it covered another highlight, that one shows again
        const styles = new Map<number, HighlightStyle>();
        for (const {visualIndex, type} of this.painted) {
            if ('hovered' !== type) {
                styles.set(visualIndex, HIGHLIGHT_STYLES[type]);
            }
        }
        clone.querySelectorAll<SVGRectElement>('#overlays-layer rect').forEach((rect) => {
            const style = styles.get(Number(rect.dataset.tileIndex));
            if (!style) {
                rect.remove(); // transparent: nothing to see
                return;
            }
            rect.setAttribute('fill', style.fill);
            rect.setAttribute('opacity', style.opacity);
        });
    }

    /**
     * Sets the SVG viewBox and publishes its aspect ratio as `--board-aspect` on
     * the game container: CSS sizes the board from it (fit to the viewport
     * height) and click/drag hit-testing needs the SVG element to match the
     * viewBox exactly - no letterboxing.
     */
    private applyViewBox(): void {
        const x = this.coordsVisible ? -COORD_WIDTH : 0;
        const width = BOARD_WIDTH + (this.coordsVisible ? COORD_WIDTH : 0);
        const height = BOARD_HEIGHT + (this.coordsVisible ? COORD_HEIGHT : 0) + TOP_MARGIN;
        this.svg.setAttribute('viewBox', `${x} -${TOP_MARGIN} ${width} ${height}`);
        const host = this.container.closest<HTMLElement>('.game-container') ?? this.container;
        host.style.setProperty('--board-aspect', (width / height).toString());
    }

    dispose(): void {
        // Remove event listeners using stored references
        if (this.boundOnResize) {
            window.removeEventListener('resize', this.boundOnResize);
        }
        if (this.boundHandleWindowMouseUp) {
            window.removeEventListener('mouseup', this.boundHandleWindowMouseUp);
        }

        if (this.svg) {
            if (this.boundHandleClick) {
                this.svg.removeEventListener('click', this.boundHandleClick);
            }
            if (this.boundHandleMouseMove) {
                this.svg.removeEventListener('mousemove', this.boundHandleMouseMove);
            }
            if (this.boundHandleMouseLeave) {
                this.svg.removeEventListener('mouseleave', this.boundHandleMouseLeave);
            }
            if (this.boundHandleMouseDown) {
                this.svg.removeEventListener('mousedown', this.boundHandleMouseDown);
            }
            if (this.boundHandleMouseUp) {
                this.svg.removeEventListener('mouseup', this.boundHandleMouseUp);
            }
            if (this.boundHandleTouchStart) {
                this.svg.removeEventListener('touchstart', this.boundHandleTouchStart);
            }
            if (this.boundHandleTouchMove) {
                this.svg.removeEventListener('touchmove', this.boundHandleTouchMove);
            }
            if (this.boundHandleTouchEnd) {
                this.svg.removeEventListener('touchend', this.boundHandleTouchEnd);
            }
        }

        // Remove SVG element
        if (this.svg && this.svg.parentNode) {
            this.svg.parentNode.removeChild(this.svg);
        }

        this.arrows?.dispose();
        this.arrows = null;

        // Clear caches
        this.overlayElements.clear();
        this.currentBoardData = null;
    }
}
