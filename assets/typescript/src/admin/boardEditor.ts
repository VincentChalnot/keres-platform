import {GameAPI} from '../network/GameAPI';
import {GameState} from '../models/GameState';
import {Board, LAST_BOARD_INDEX, PIECE_CODE, Piece} from '../models/types';
import {
    decodePiece,
    encodeBoardToBinary,
    encodeBoardToHash,
    encodePiece,
    parseBoardCode,
    posToAlgebraic,
} from '../utils/boardUtils';
import {downloadFile} from '../views/boardExport';
import SVGBoardView from '../views/SVGBoardView';

/**
 * Admin board editor: builds a position from scratch on the very `SVGBoardView` the game uses, with arrows, and
 * exports it as static HTML (`SVGBoardView.exportStaticHtml`). No engine call, no Mercure, no backend state: the
 * position is a plain 83-byte board (`utils/boardUtils.ts`), shown as its base64 "board code" and kept in
 * `location.hash`. The only server round trip is the starting position, asked from the engine like a game does.
 *
 * The view stays a view: editing is done through its click / drag callbacks and the position is pushed to it with
 * `render()`. Arrows are view state and survive every edit; only "Clear arrows" removes them.
 */

const KING = 'king';
/** Every piece the sprite sheet draws: the king, then the seven stackable types of the wire format. */
const PIECE_TYPES: string[] = [KING, ...Object.values(PIECE_CODE)];

const SQUARE_COUNT = LAST_BOARD_INDEX + 1;

const HISTORY_LIMIT = 200;
const EXPORT_TITLE = 'Keres board';
const EXPORT_FILENAME = 'keres-board.html';
const EXPORT_SVG_FILENAME = 'keres-board.svg';
const COPIED_LABEL_MS = 1500;

type Tool = {kind: 'eraser'} | {kind: 'piece'; color: boolean; type: string};
type Placement = 'replace' | 'stack';

/** White to move, no-capture counter 0: the editor only deals with "the board position". */
function emptyPosition(): Uint8Array {
    return encodeBoardToBinary(new Board(new Array<Piece | null>(SQUARE_COUNT).fill(null), true));
}

/** The same squares with the editor's fixed flags and counter, whatever the source had in them. */
function withEditorFlags(bytes: Uint8Array): Uint8Array {
    const position = emptyPosition();
    position.set(bytes.subarray(0, SQUARE_COUNT));
    return position;
}

/** A board code as typed or pasted, normalised to the editor's flags and counter. */
function parseEditorCode(text: string): {position: Uint8Array} | {error: string} {
    const parsed = parseBoardCode(text);

    return 'error' in parsed ? parsed : {position: withEditorFlags(parsed.bytes)};
}

/** The code in the URL hash, percent-decoded (a link pasted through a chat tool may have its `=` escaped). */
function readHashCode(): string {
    const raw = window.location.hash.slice(1);
    try {
        return decodeURIComponent(raw);
    } catch {
        return raw;
    }
}

function requireElement<T extends HTMLElement>(id: string): T {
    const element = document.getElementById(id);
    if (!element) {
        throw new Error(`Board editor: #${id} is missing from the page.`);
    }

    return element as T;
}

export async function initBoardEditor(): Promise<void> {
    const root = document.getElementById('board-editor');
    if (!root) {
        return; // not on the board editor page
    }

    const boardContainer = requireElement<HTMLElement>('board-editor-board');
    const palette = requireElement<HTMLElement>('board-editor-palette');
    const statusLine = requireElement<HTMLElement>('board-editor-status');
    const codeField = requireElement<HTMLTextAreaElement>('board-editor-code');
    const codeError = requireElement<HTMLElement>('board-editor-code-error');
    const copyButton = requireElement<HTMLButtonElement>('board-editor-copy');
    const startButton = requireElement<HTMLButtonElement>('board-editor-start');
    const undoButton = requireElement<HTMLButtonElement>('board-editor-undo');
    const redoButton = requireElement<HTMLButtonElement>('board-editor-redo');
    const coordinatesBox = requireElement<HTMLInputElement>('board-editor-coordinates');
    const rotateBox = requireElement<HTMLInputElement>('board-editor-rotate');
    const placementButtons = Array.from(root.querySelectorAll<HTMLButtonElement>('[data-placement]'));

    // The game's own view; the state object only carries the orientation here (no board, no moves, no selection)
    const gameState = new GameState();
    const view = new SVGBoardView(gameState);
    await view.initialize(boardContainer);
    view.setAnimationSpeed('instant');
    view.setCoordinatesVisible(coordinatesBox.checked);
    view.setOpponentRotated(rotateBox.checked);

    let position = emptyPosition();
    const undoStack: Uint8Array[] = [];
    const redoStack: Uint8Array[] = [];
    let tool: Tool = {kind: 'piece', color: true, type: KING};
    let placement: Placement = 'replace';
    // The view also calls the click callback when a drag starts or is cancelled (it "selects" the source tile);
    // those happen with the button down, a real click is reported after the button is up
    let pointerDown = false;

    function setStatus(message: string | null): void {
        statusLine.textContent = message ?? '';
    }

    function setCodeError(message: string | null): void {
        codeError.textContent = message ?? '';
        codeField.classList.toggle('is-invalid', null !== message);
    }

    /** Shows `position` everywhere it appears: the board, the code field, the URL, the undo buttons. */
    function show(): void {
        void view.render(position, gameState.isBoardFlipped());
        const code = encodeBoardToHash(position);
        codeField.value = code;
        setCodeError(null);
        window.history.replaceState(null, '', `#${code}`);
        undoButton.disabled = 0 === undoStack.length;
        redoButton.disabled = 0 === redoStack.length;
    }

    /** Makes `next` the position (a no-op when it is the current one) and remembers the previous one for undo. */
    function commit(next: Uint8Array): void {
        if (next.every((byte, index) => byte === position[index])) {
            return;
        }
        undoStack.push(position);
        if (undoStack.length > HISTORY_LIMIT) {
            undoStack.shift();
        }
        redoStack.length = 0;
        position = next;
        setStatus(null);
        show();
    }

    function undo(): void {
        const previous = undoStack.pop();
        if (!previous) {
            return;
        }
        redoStack.push(position);
        position = previous;
        setStatus(null);
        show();
    }

    function redo(): void {
        const next = redoStack.pop();
        if (!next) {
            return;
        }
        undoStack.push(position);
        position = next;
        setStatus(null);
        show();
    }

    function setSquare(index: number, piece: Piece | null): void {
        const next = position.slice();
        next[index] = piece ? encodePiece(piece) : 0;
        commit(next);
    }

    /** What a click on `index` does with the selected tool; refuses (with a message) what the wire format cannot hold. */
    function applyTool(index: number): void {
        if ('eraser' === tool.kind) {
            setSquare(index, null);
            return;
        }
        if ('replace' === placement) {
            setSquare(index, {color: tool.color, bottom: tool.type, top: null});
            return;
        }

        // Stack on top: the piece joins the one already there (replacing the top of an existing stack)
        const below = decodePiece(position[index]);
        if (KING === tool.type) {
            setStatus('A king cannot be part of a stack.');
        } else if (!below) {
            setStatus(`Nothing to stack on: ${posToAlgebraic(index)} is empty.`);
        } else if (below.color !== tool.color) {
            setStatus('A stack holds pieces of one colour only.');
        } else if (KING === below.bottom) {
            setStatus('Nothing can be stacked on a king.');
        } else {
            setSquare(index, {color: below.color, bottom: below.bottom, top: tool.type});
        }
    }

    // ── Board interaction (left button; the right one belongs to the arrows, handled by the view) ──
    view.onTileClick((index) => {
        if (!pointerDown) {
            applyTool(index);
        }
    });
    view.onDragMove((from, to) => {
        // The view tells us from inside the mouseup. Moving the dragged piece's element right now would remove the
        // mousedown target before the browser dispatches the click: no click is sent then, and the view, which
        // swallows "the click that follows a drag", would swallow the next real one instead. Let the click go first.
        window.setTimeout(() => {
            if (0 === position[from]) {
                return; // nothing to carry
            }
            const next = position.slice();
            next[to] = next[from];
            next[from] = 0;
            commit(next);
        }, 0);
    });
    boardContainer.addEventListener('mousedown', (event) => {
        if (0 === event.button) {
            pointerDown = true;
        }
    });
    window.addEventListener('mouseup', () => {
        pointerDown = false;
    });

    // ── Palette ──
    const toolButtons: Array<{tool: Tool; button: HTMLButtonElement}> = [];

    function refreshPalette(): void {
        for (const entry of toolButtons) {
            const selected = 'eraser' === entry.tool.kind
                ? 'eraser' === tool.kind
                : 'piece' === tool.kind && entry.tool.color === tool.color && entry.tool.type === tool.type;
            entry.button.setAttribute('aria-pressed', String(selected));
        }
    }

    function addToolButton(parent: HTMLElement, entry: Tool, label: string, content: string): void {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'board-editor__tool';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.innerHTML = content;
        button.addEventListener('click', () => {
            tool = entry;
            setStatus(null);
            refreshPalette();
        });
        parent.appendChild(button);
        toolButtons.push({tool: entry, button});
    }

    for (const color of [true, false]) {
        const group = document.createElement('div');
        group.className = 'board-editor__group';
        const heading = document.createElement('p');
        heading.className = 'eyebrow';
        heading.textContent = color ? 'White' : 'Black';
        const row = document.createElement('div');
        row.className = `board-editor__row board-editor__row--${color ? 'white' : 'black'}`;
        for (const type of PIECE_TYPES) {
            const name = `${color ? 'White' : 'Black'} ${type}`;
            // The sprite symbols are inlined in the board's <svg>, so the palette draws exactly what the board draws
            addToolButton(
                row,
                {kind: 'piece', color, type},
                name,
                `<svg viewBox="-5 -5 110 90" aria-hidden="true"><use href="#piece-${type}" class="piece ${color ? 'p-w' : 'p-b'}"/></svg>`,
            );
        }
        group.append(heading, row);
        palette.appendChild(group);
    }
    const eraserRow = document.createElement('div');
    eraserRow.className = 'board-editor__group';
    addToolButton(
        eraserRow,
        {kind: 'eraser'},
        'Eraser',
        '<span class="icon"><i class="fa-solid fa-eraser" aria-hidden="true"></i></span><span>Eraser</span>',
    );
    eraserRow.firstElementChild?.classList.add('board-editor__tool--eraser');
    palette.appendChild(eraserRow);
    refreshPalette();

    for (const button of placementButtons) {
        button.addEventListener('click', () => {
            placement = 'stack' === button.dataset.placement ? 'stack' : 'replace';
            setStatus(null);
            for (const other of placementButtons) {
                const selected = other === button;
                other.setAttribute('aria-pressed', String(selected));
            }
        });
    }

    // ── Position buttons and display switches ──
    requireElement<HTMLButtonElement>('board-editor-clear').addEventListener('click', () => commit(emptyPosition()));

    startButton.addEventListener('click', () => {
        startButton.disabled = true;
        // The initial board comes from the engine like in a game: replaying zero moves
        new GameAPI().replayMoves([])
            .then((board) => commit(withEditorFlags(encodeBoardToBinary(board))))
            .catch((error: unknown) => setStatus(`Could not get the starting position: ${error instanceof Error ? error.message : String(error)}`))
            .finally(() => {
                startButton.disabled = false;
            });
    });

    requireElement<HTMLButtonElement>('board-editor-flip').addEventListener('click', () => {
        gameState.flipBoard();
        void view.render(position, gameState.isBoardFlipped());
    });
    coordinatesBox.addEventListener('change', () => view.setCoordinatesVisible(coordinatesBox.checked));
    rotateBox.addEventListener('change', () => view.setOpponentRotated(rotateBox.checked));
    undoButton.addEventListener('click', undo);
    redoButton.addEventListener('click', redo);
    document.addEventListener('keydown', (event) => {
        // In the code field the browser's own undo works on the text
        if (!(event.ctrlKey || event.metaKey) || event.target instanceof HTMLTextAreaElement || event.target instanceof HTMLInputElement) {
            return;
        }
        const key = event.key.toLowerCase();
        if ('z' === key && !event.shiftKey) {
            event.preventDefault();
            undo();
        } else if ('y' === key || ('z' === key && event.shiftKey)) {
            event.preventDefault();
            redo();
        }
    });

    // ── Board code ──
    codeField.addEventListener('input', () => {
        const parsed = parseEditorCode(codeField.value);
        if ('error' in parsed) {
            setCodeError(`${parsed.error} The board is unchanged.`);
            return;
        }
        setCodeError(null);
        commit(parsed.position);
        codeField.value = encodeBoardToHash(position); // the canonical code (flags and counter normalised)
    });

    let copiedTimer: number | undefined;
    copyButton.addEventListener('click', () => {
        const code = encodeBoardToHash(position);
        navigator.clipboard.writeText(code).then(() => {
            copyButton.textContent = 'Copied';
            window.clearTimeout(copiedTimer);
            copiedTimer = window.setTimeout(() => {
                copyButton.textContent = 'Copy';
            }, COPIED_LABEL_MS);
        }).catch(() => {
            codeField.focus();
            codeField.select();
            setStatus('The browser refused to copy: the code is selected, press Ctrl+C.');
        });
    });

    // A bookmark, a back/forward step or a hand-edited URL changes the hash without reloading the page
    window.addEventListener('hashchange', () => {
        const code = readHashCode();
        const parsed = parseEditorCode(code);
        if ('error' in parsed) {
            // The position on screen stays; the URL goes back to describing it
            window.history.replaceState(null, '', `#${encodeBoardToHash(position)}`);
            if ('' !== code) {
                setCodeError(`The board code in the URL was ignored: ${parsed.error}`);
            }
            return;
        }
        setCodeError(null);
        commit(parsed.position);
    });

    // ── Arrows and export ──
    requireElement<HTMLButtonElement>('board-editor-clear-arrows').addEventListener('click', () => view.clearArrows());
    requireElement<HTMLButtonElement>('board-editor-export').addEventListener('click', () => {
        try {
            downloadFile(view.exportStaticHtml(EXPORT_TITLE), EXPORT_FILENAME, 'text/html');
        } catch (error) {
            setStatus(`Export failed: ${error instanceof Error ? error.message : String(error)}`);
        }
    });
    requireElement<HTMLButtonElement>('board-editor-export-svg').addEventListener('click', () => {
        try {
            downloadFile(view.exportStaticSvg(), EXPORT_SVG_FILENAME, 'image/svg+xml');
        } catch (error) {
            setStatus(`Export failed: ${error instanceof Error ? error.message : String(error)}`);
        }
    });

    // ── First display: the position of the URL, else an empty board ──
    const initialCode = readHashCode();
    let initialError: string | null = null;
    if ('' !== initialCode) {
        const parsed = parseEditorCode(initialCode);
        if ('error' in parsed) {
            initialError = `The board code in the URL was ignored: ${parsed.error}`;
        } else {
            position = parsed.position;
        }
    }
    show();
    if (initialError) {
        setCodeError(initialError);
    }
}
