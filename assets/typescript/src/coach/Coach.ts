import {GameState} from '../models/GameState';
import {Move, Piece} from '../models/types';
import {GameController} from '../controllers/GameController';
import {GameAPI} from '../network/GameAPI';
import {pieceRule} from '../models/pieceRules';
import {posToAlgebraic} from '../utils/boardUtils';
import {t} from '../i18n';
import {findLoss, Loss} from './hanging';

/** What the player said to "Do you play chess?" (`App\Model\ChessExperience`), '' when never asked. */
export type CoachExperience = 'player' | 'beginner' | 'skipped' | '';

export interface CoachDeps {
    api: GameAPI;
    gameState: GameState;
    controller: GameController;
    playerWhite: boolean;
    experience: CoachExperience;
    /** Asks the server (or the guest endpoint) for the engine's best move; rejects with the message to show. */
    requestHint: () => Promise<Move>;
    /** The page's own undo (it removes the bot's reply too). */
    takeBack: () => Promise<void>;
    drawHint: (move: Move) => void;
}

type Kind = 'hint' | 'blunder' | 'threat' | 'piece' | 'tip';

/** One message at a time: the first kind present wins. */
const PRIORITY: Kind[] = ['hint', 'blunder', 'threat', 'piece', 'tip'];

interface Message {
    text: string;
    image?: {src: string; alt: string};
    actions: {label: string; run: () => void}[];
    squares: number[];
}

/** The piece types a player who knows chess has not met (D8): the other cards would only repeat what they know. */
const NEW_PIECES = ['ballista', 'paladin', 'guard'];
const ALL_PIECES = ['soldier', 'bishop', 'rook', 'knight', 'paladin', 'guard', 'ballista', 'king'];
/** Phase tips are for the opening only. */
const TIP_PLIES = 8;

const SEEN_KEY = 'keres.coach.seen';

/** Tips and piece cards already shown, per browser (a tip is never repeated once dismissed). */
function readSeen(): Set<string> {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(SEEN_KEY) ?? '[]');

        return new Set(Array.isArray(parsed) ? parsed.filter((id): id is string => 'string' === typeof id) : []);
    } catch {
        return new Set();
    }
}

/**
 * The coach (casual games against the bot only): a card above the piece panel that says one thing at a time - a hint's
 * arrow, a take-back offer for a move that left a piece unprotected, a warning that a piece can be captured, the first
 * card of each piece type, a tip for the opening. It only reads positions (`/api/moves`, the same data as the threat
 * overlay); the hint is the engine's answer to `requestHint`.
 */
export class Coach {
    private readonly card = document.getElementById('coach-card') as HTMLDetailsElement;
    private readonly messageEl = document.getElementById('coach-message') as HTMLElement;
    private readonly imageEl = document.getElementById('coach-image') as HTMLImageElement;
    private readonly actionsEl = document.getElementById('coach-actions') as HTMLElement;
    private readonly hintBtn = document.getElementById('coach-hint-btn') as HTMLButtonElement;
    private readonly slots: Partial<Record<Kind, Message>> = {};
    private readonly seen = readSeen();
    private enabled = false;
    private lastKey = '';
    private analysisToken = 0;
    private hintBusy = false;
    private markedSquares = '';
    private pendingBlunder: {ply: number; loss: Loss} | null = null;

    constructor(private readonly deps: CoachDeps) {
        this.hintBtn.addEventListener('click', () => void this.hint());
        if (window.matchMedia('(max-width: 767px)').matches) {
            this.card.open = false;
        }
    }

    setEnabled(enabled: boolean): void {
        this.enabled = enabled;
        this.lastKey = '';
        this.pendingBlunder = null;
        this.clear('blunder', 'threat', 'piece', 'tip', 'hint');
        this.onPositionChanged();
    }

    /** The displayed position may have changed (a move, the bot's reply, an undo, a history jump): refresh what the coach says. */
    onPositionChanged(): void {
        const key = this.positionKey();
        if (key === this.lastKey) {
            this.render();
            return;
        }

        this.lastKey = key;
        this.analysisToken++;
        this.clear('hint', 'threat', 'piece', 'tip');
        const plies = this.deps.gameState.getMoveList().length;
        if (this.pendingBlunder && (plies < this.pendingBlunder.ply || plies > this.pendingBlunder.ply + 1)) {
            this.pendingBlunder = null;
            this.clear('blunder');
        }

        if (this.enabled && this.isPlayerTurn()) {
            this.showPendingBlunder();
            this.showTip();
            void this.analyseThreats(this.analysisToken);
        } else {
            this.clear('blunder');
        }

        this.render();
    }

    /** The player's move has landed and the bot has not replied yet: would the bot win material on this position? */
    onPlayerMoved(): void {
        this.pendingBlunder = null;
        this.clear('blunder');
        const board = this.deps.gameState.getBoard();
        if (!this.enabled || !board || board.isGameOver() || board.whiteToMove === this.deps.playerWhite) {
            return;
        }

        const ply = this.deps.gameState.getMoveList().length;
        const attacks = this.deps.gameState.getPotentialMoves();
        void findLoss(this.deps.api, board, attacks, this.deps.playerWhite).then((loss) => {
            if (loss && this.deps.gameState.getMoveList().length >= ply) {
                this.pendingBlunder = {ply, loss};
                this.lastKey = '';
                this.onPositionChanged();
            }
        });
    }

    /** A piece is selected: the first time its type comes up, the coach introduces it. */
    onSelection(position: number | null): void {
        if (!this.enabled || null === position || this.deps.gameState.getSelectedPosition() !== position) return;

        const piece = this.deps.gameState.getBoard()?.getPieceAt(position);
        if (!piece) return;

        const types = [piece.bottom, ...(piece.top ? [piece.top] : [])];
        const type = types.find((candidate) => this.pieceCardFor(candidate));
        if (!type) return;

        const id = `piece:${type}`;
        this.seen.add(id);
        this.persist();
        this.slots.piece = {
            text: t(`play.coach.piece.${type}`),
            image: {src: `/help/pieces/${type}-${piece.color ? 'white' : 'black'}.svg`, alt: t('help.pieces.diagram_alt', {piece: this.nameOf(type)})},
            actions: [{label: t('play.coach.got_it'), run: () => this.clear('piece')}],
            squares: [],
        };
        this.render();
    }

    private pieceCardFor(type: string): boolean {
        const pool = 'player' === this.deps.experience ? NEW_PIECES : ALL_PIECES;

        return pool.includes(type) && !this.seen.has(`piece:${type}`);
    }

    private positionKey(): string {
        const state = this.deps.gameState;

        return `${state.getMoveList().length}:${state.getCurrentMoveIndex()}:${state.getBoard()?.isGameOver() ? 'over' : 'live'}`;
    }

    /** The latest position, the player to move, game not over. */
    private isPlayerTurn(): boolean {
        const board = this.deps.gameState.getBoard();
        const state = this.deps.gameState;

        return null !== board
            && !board.isGameOver()
            && board.whiteToMove === this.deps.playerWhite
            && state.getCurrentMoveIndex() >= state.getMoveList().length - 1;
    }

    private showPendingBlunder(): void {
        const pending = this.pendingBlunder;
        if (!pending || this.deps.gameState.getMoveList().length !== pending.ply + 1) return;

        const dismiss = (): void => {
            this.pendingBlunder = null;
            this.clear('blunder');
        };
        this.slots.blunder = {
            text: t('play.coach.blunder', {piece: this.describe(pending.loss.piece)}),
            actions: [
                {label: t('play.coach.take_back'), run: () => void this.deps.takeBack().then(dismiss)},
                {label: t('play.coach.keep'), run: dismiss},
            ],
            squares: [pending.loss.square],
        };
    }

    private showTip(): void {
        const plies = this.deps.gameState.getMoveList().length;
        if (plies >= TIP_PLIES) return;

        let id: string | null = null;
        if ('beginner' === this.deps.experience) {
            id = plies < 2 ? 'tip:advance' : (plies < 6 ? 'tip:develop' : null);
        } else if ('player' === this.deps.experience) {
            id = 'tip:principles';
        }
        if (!id || this.seen.has(id)) return;

        const tipId = id;
        const remember = (): void => {
            this.seen.add(tipId);
            this.persist();
        };
        // The chess player's tip is for the first game only: showing it once is enough.
        if ('tip:principles' === tipId) {
            remember();
        }
        this.slots.tip = {
            text: t(`play.coach.${tipId.replace(':', '.')}`),
            actions: [{label: t('play.coach.got_it'), run: () => {
                remember();
                this.clear('tip');
            }}],
            squares: [],
        };
    }

    private async analyseThreats(token: number): Promise<void> {
        const board = this.deps.gameState.getBoard();
        if (!board) return;

        const loss = await findLoss(this.deps.api, board, this.deps.gameState.getOpponentThreats(), this.deps.playerWhite);
        if (token !== this.analysisToken || !loss) return;

        this.slots.threat = {
            text: t('play.coach.threat', {piece: this.describe(loss.piece), square: posToAlgebraic(loss.square)}),
            actions: [],
            squares: [loss.square],
        };
        this.render();
    }

    private async hint(): Promise<void> {
        if (this.hintBusy || !this.enabled || !this.isPlayerTurn()) return;

        this.hintBusy = true;
        this.render();
        try {
            const move = await this.deps.requestHint();
            this.deps.drawHint(move);
            this.slots.hint = {text: t('play.coach.hint_shown'), actions: [], squares: []};
        } catch (error) {
            this.slots.hint = {text: (error as Error).message, actions: [], squares: []};
        } finally {
            this.hintBusy = false;
            this.render();
        }
    }

    private clear(...kinds: Kind[]): void {
        for (const kind of kinds) {
            delete this.slots[kind];
        }
        this.render();
    }

    private persist(): void {
        try {
            window.localStorage.setItem(SEEN_KEY, JSON.stringify([...this.seen]));
        } catch {
            // Storage unavailable: the cards simply come back next time.
        }
    }

    private nameOf(type: string): string {
        return (pieceRule(type)?.name ?? type).replace(/\s*\(.*\)$/, '').toLowerCase();
    }

    private describe(piece: Piece): string {
        return piece.top
            ? t('play.coach.stack', {top: this.nameOf(piece.top), bottom: this.nameOf(piece.bottom)})
            : this.nameOf(piece.bottom);
    }

    private render(): void {
        const visible = this.enabled && !this.deps.gameState.getBoard()?.isGameOver();
        this.card.hidden = !visible;
        const message = visible ? this.slots[PRIORITY.find((kind) => this.slots[kind]) as Kind] : undefined;

        this.messageEl.textContent = message?.text ?? t('play.coach.idle');
        this.imageEl.hidden = !message?.image;
        if (message?.image) {
            this.imageEl.src = message.image.src;
            this.imageEl.alt = message.image.alt;
        }
        this.actionsEl.replaceChildren(...(message?.actions ?? []).map((action) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'game-button is-small';
            button.textContent = action.label;
            button.addEventListener('click', action.run);

            return button;
        }));
        this.hintBtn.disabled = this.hintBusy || !this.isPlayerTurn();
        const squares = message?.squares ?? [];
        if (squares.join() !== this.markedSquares) {
            this.markedSquares = squares.join();
            this.deps.controller.setExtraHighlights(squares.map((position) => ({position, type: 'threat' as const})));
        }
    }
}
