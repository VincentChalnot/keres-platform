import {Board, Move} from '../models/types';
import {decodeMove, encodeMove, encodeMoveListToBase64, encodeMoveListToBinary} from '../utils/boardUtils';
import {GameAPI, GameStatePayload} from './GameAPI';
import {GameUpdate} from './MercureClient';
import {t} from '../i18n';

const OPPONENT_TYPE_AI = 0;

/** What a guest game persists in localStorage between page loads. */
export interface GuestGameRecord {
    opponentType: number;
    playerWhite: boolean;
    /** AI difficulty (1-10, guests are limited to 1-4 by the server); absent in games saved before it existed. */
    aiLevel?: number;
    moves: number[];
    resignedColor: 'white' | 'black' | null;
}

const STORAGE_KEY = 'keres.guestGame.v1';

/**
 * A guest (no-account) AI or hot-seat game, played entirely in the browser.
 * Swaps the three game-mutating calls of `GameAPI` (move, undo, resign) for
 * local equivalents backed by localStorage; legal moves and board replay
 * still go through the engine relays under `/api`, and the AI's reply comes
 * from `/api/engine-move-game` instead of the server-side Messenger worker.
 * The reply is delivered through `onRemoteUpdate`, exactly where a Mercure
 * update would arrive for a persisted game.
 */
export class LocalGameAPI extends GameAPI {
    private remoteUpdateListener: ((update: GameUpdate) => void) | null = null;
    /** The position (move list) the in-flight engine request was made for; null when none. */
    private pendingAiPosition: string | null = null;

    constructor(private record: GuestGameRecord) {
        super();
    }

    /** The saved game, or null when there is none (or it's unreadable). */
    static load(): GuestGameRecord | null {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw) as Partial<GuestGameRecord>;
            if (typeof parsed.opponentType !== 'number' || typeof parsed.playerWhite !== 'boolean' || !Array.isArray(parsed.moves)) {
                return null;
            }
            return {
                opponentType: parsed.opponentType,
                playerWhite: parsed.playerWhite,
                aiLevel: typeof parsed.aiLevel === 'number' ? parsed.aiLevel : 1,
                moves: parsed.moves.filter((m): m is number => typeof m === 'number'),
                resignedColor: parsed.resignedColor === 'white' || parsed.resignedColor === 'black' ? parsed.resignedColor : null,
            };
        } catch {
            return null;
        }
    }

    static start(opponentType: number, playerWhite: boolean, aiLevel: number = 1): GuestGameRecord {
        const record: GuestGameRecord = {opponentType, playerWhite, aiLevel, moves: [], resignedColor: null};
        LocalGameAPI.persist(record);
        return record;
    }

    private static persist(record: GuestGameRecord): void {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(record));
        } catch {
            // Private mode / quota: the game still plays, it just won't survive a reload.
        }
    }

    onRemoteUpdate(listener: (update: GameUpdate) => void): void {
        this.remoteUpdateListener = listener;
    }

    getMoves(): Move[] {
        return this.record.moves.map(decodeMove);
    }

    async submitMove(move: Move): Promise<GameStatePayload> {
        this.record.moves.push(encodeMove(move));
        this.recordLastMove();
        this.record.resignedColor = null;
        const payload = await this.buildPayload();
        this.save();

        // The AI reply is requested by the page once the controller has
        // finished applying this move ('moveSubmitted'), never interleaved with it.
        return payload;
    }

    async undoMove(): Promise<string> {
        if (0 === this.record.moves.length) {
            throw new Error(t('play.error.no_moves_to_undo'));
        }

        this.record.moves.pop();

        // Against the AI, take back to the player's own turn (mirrors UndoMoveAction).
        if (OPPONENT_TYPE_AI === this.record.opponentType && this.isAiTurn(this.record.moves.length) && this.record.moves.length > 0) {
            this.record.moves.pop();
        }

        this.record.resignedColor = null;
        this.save();

        return encodeMoveListToBase64(this.getMoves());
    }

    async resign(): Promise<GameStatePayload> {
        const whiteToMove = 0 === this.record.moves.length % 2;
        this.record.resignedColor = OPPONENT_TYPE_AI === this.record.opponentType
            ? (this.record.playerWhite ? 'white' : 'black')
            : (whiteToMove ? 'white' : 'black');
        this.save();

        return this.buildPayload();
    }

    /** How the saved game ended (resignation, or the engine's verdict with its reason), re-applied after the initial replay on page load; null while it is in progress. */
    async restoredEnding(): Promise<GameStatePayload | null> {
        const payload = await this.buildPayload();

        return payload.gameOver ? payload : null;
    }

    /** Asks the engine for its reply when it's the AI's move (after a player move, or on reload). */
    requestAiMoveIfDue(board: Board): void {
        const position = this.positionKey();

        if (OPPONENT_TYPE_AI !== this.record.opponentType || board.isGameOver() || null !== this.record.resignedColor) {
            return;
        }

        if (!this.isAiTurn(this.record.moves.length) || this.pendingAiPosition === position) {
            return;
        }

        this.pendingAiPosition = position;
        void this.playAiMove(position).finally(() => {
            if (this.pendingAiPosition === position) {
                this.pendingAiPosition = null;
            }
        });
    }

    private positionKey(): string {
        return this.record.moves.join(',');
    }

    private async playAiMove(position: string): Promise<void> {
        const response = await fetch(`/api/engine-move-game?level=${this.record.aiLevel ?? 1}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: encodeMoveListToBinary(this.getMoves()) as BodyInit,
        });

        if (!response.ok) {
            window.dispatchEvent(new CustomEvent('showError', {detail: {message: t('play.error.ai_failed', {status: response.status})}}));
            return;
        }

        // An undo (or a resignation) while the engine was thinking makes this reply stale.
        if (this.positionKey() !== position || null !== this.record.resignedColor) {
            return;
        }

        const [aiMove] = new Uint16Array(await response.arrayBuffer());
        this.record.moves.push(aiMove);
        this.recordLastMove();
        const payload = await this.buildPayload();
        this.save();

        this.remoteUpdateListener?.({...payload, seq: this.record.moves.length, rating: null});
    }

    /**
     * Feeds the last move to the server's analytics tree (`/api/guest-moves`,
     * the server replays the whole list itself). Fire and forget: a failure
     * must never disturb the game.
     */
    private recordLastMove(): void {
        void fetch('/api/guest-moves', {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: encodeMoveListToBinary(this.getMoves()) as BodyInit,
            keepalive: true,
        }).catch(() => undefined);
    }

    private isAiTurn(plies: number): boolean {
        const whiteToMove = 0 === plies % 2;

        return whiteToMove !== this.record.playerWhite;
    }

    private async buildPayload(): Promise<GameStatePayload> {
        const board = await this.replayMoves(this.getMoves());
        const resigned = this.record.resignedColor;

        if (null !== resigned) {
            board.gameOver = true;
            board.whiteWins = 'black' === resigned;
            board.draw = false;
        }

        const gameOver = board.isGameOver();
        const engineEndCode = gameOver && null === resigned ? await this.fetchGameOverReason(this.getMoves()) : null;
        const result = !gameOver ? null : (board.draw ? 'draw' : (board.whiteWins ? 'white' : 'black'));

        return {
            board,
            moves: [...this.record.moves],
            status: gameOver ? 'finished' : (0 === this.record.moves.length ? 'created' : 'ongoing'),
            endReason: null !== resigned ? 'resignation' : (gameOver ? 'engine' : 'none'),
            engineEndCode,
            result,
            gameOver,
            whiteWins: board.whiteWins,
            draw: board.draw,
            clock: null,
            aiLevel: null,
            serverTime: Date.now() * 1000,
        };
    }

    private save(): void {
        LocalGameAPI.persist(this.record);
    }
}
