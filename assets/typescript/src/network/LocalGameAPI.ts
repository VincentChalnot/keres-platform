import {Board, Move} from '../models/types';
import {decodeMove, encodeMove, encodeMoveListToBase64, encodeMoveListToBinary} from '../utils/boardUtils';
import {GameAPI, GameStatePayload} from './GameAPI';
import {GameUpdate} from './MercureClient';

const OPPONENT_TYPE_AI = 0;

/** What a guest game persists in localStorage between page loads. */
export interface GuestGameRecord {
    opponentType: number;
    playerWhite: boolean;
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
                moves: parsed.moves.filter((m): m is number => typeof m === 'number'),
                resignedColor: parsed.resignedColor === 'white' || parsed.resignedColor === 'black' ? parsed.resignedColor : null,
            };
        } catch {
            return null;
        }
    }

    static start(opponentType: number, playerWhite: boolean): GuestGameRecord {
        const record: GuestGameRecord = {opponentType, playerWhite, moves: [], resignedColor: null};
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
        this.record.resignedColor = null;
        const payload = await this.buildPayload();
        this.save();

        // The AI reply is requested by the page once the controller has
        // finished applying this move ('moveSubmitted'), never interleaved with it.
        return payload;
    }

    async undoMove(): Promise<string> {
        if (0 === this.record.moves.length) {
            throw new Error('No moves to undo');
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

    /** The saved resignation, re-applied after the initial replay on page load. */
    async restoredResignation(): Promise<GameStatePayload | null> {
        return null === this.record.resignedColor ? null : this.buildPayload();
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
        const response = await fetch('/api/engine-move-game', {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: encodeMoveListToBinary(this.getMoves()) as BodyInit,
        });

        if (!response.ok) {
            window.dispatchEvent(new CustomEvent('showError', {detail: {message: `The AI could not play (server returned ${response.status}). Use Undo, then try again.`}}));
            return;
        }

        // An undo (or a resignation) while the engine was thinking makes this reply stale.
        if (this.positionKey() !== position || null !== this.record.resignedColor) {
            return;
        }

        const [aiMove] = new Uint16Array(await response.arrayBuffer());
        this.record.moves.push(aiMove);
        const payload = await this.buildPayload();
        this.save();

        this.remoteUpdateListener?.({...payload, seq: this.record.moves.length, rating: null});
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
        const result = !gameOver ? null : (board.draw ? 'draw' : (board.whiteWins ? 'white' : 'black'));

        return {
            board,
            moves: [...this.record.moves],
            status: gameOver ? 'finished' : (0 === this.record.moves.length ? 'created' : 'ongoing'),
            endReason: null !== resigned ? 'resignation' : (gameOver ? 'engine' : 'none'),
            result,
            gameOver,
            whiteWins: board.whiteWins,
            draw: board.draw,
            clock: null,
            serverTime: Date.now() * 1000,
        };
    }

    private save(): void {
        LocalGameAPI.persist(this.record);
    }
}
