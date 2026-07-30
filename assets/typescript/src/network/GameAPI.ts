import {Board, PotentialMove, Move} from '../models/types';
import {decodeBoardFromBinary, encodeBoardToBinary, decodePotentialMove, encodeMove, encodeMoveListToBinary} from '../utils/boardUtils';
import {ClockState} from './MercureClient';

/**
 * Full authoritative game state returned by the move/finish endpoints.
 * Mirrors the JSON payload built by GameStatePayloadBuilder (PHP).
 */
export interface GameStatePayload {
    board: Board;
    moves: number[];
    status: string;
    endReason: string;
    result: string | null;
    gameOver: boolean;
    whiteWins: boolean;
    draw: boolean;
    clock: ClockState | null;
    serverTime: number;
}

/**
 * API client for game backend
 * Handles binary communication with server and converts to/from objects
 */
export class GameAPI {
    private readonly backendUrl: string;
    private gameUuid: string | null = null;

    constructor() {
        // Set backend URL from current location
        this.backendUrl = `${window.location.protocol}//${window.location.hostname}`;
        if (window.location.port) {
            this.backendUrl += `:${window.location.port}`;
        }
        this.backendUrl += '/api';
        
        // Get game UUID from board container data attribute
        const boardContainer = document.getElementById('board-container');
        if (boardContainer) {
            this.gameUuid = boardContainer.getAttribute('data-game-uuid');
        }
    }

    /**
     * Get potential moves for current board state
     */
    async getPotentialMoves(board: Board): Promise<PotentialMove[]> {
        return this.fetchMoves(board);
    }

    /**
     * Get opponent threats by fetching moves with inverted turn
     */
    async getOpponentThreats(board: Board): Promise<PotentialMove[]> {
        // Create a copy of the board with inverted turn
        const invertedBoard = new Board(
            [...board.cells],
            !board.whiteToMove,  // Invert the turn
            board.gameOver,
            board.whiteWins,
            board.draw,
            board.movesWithoutCapture
        );
        
        return this.fetchMoves(invertedBoard);
    }

    /**
     * Private helper to fetch moves for a given board state
     */
    private async fetchMoves(board: Board): Promise<PotentialMove[]> {
        const binary = encodeBoardToBinary(board);
        const response = await fetch(`${this.backendUrl}/moves`, {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: binary as BodyInit,
        });
        const buffer = await response.arrayBuffer();
        const movesU16 = new Uint16Array(buffer);

        return Array.from(movesU16).map(decodePotentialMove);
    }

    /**
     * Submit a move to the game and get the new board state.
     * Returns the full authoritative payload (board, moves, game-over
     * verdict, clock) exactly as built by GameStatePayloadBuilder.
     */
    async submitMove(move: Move): Promise<GameStatePayload> {
        if (!this.gameUuid) {
            throw new Error('No game UUID available');
        }

        const moveU16 = encodeMove(move);
        const moveBuffer = new Uint16Array([moveU16]).buffer;

        const response = await fetch(`/play/${this.gameUuid}/move`, {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: moveBuffer as BodyInit,
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || 'Failed to submit move');
        }

        const data = await response.json();
        return this.parsePayload(data);
    }

    /**
     * Parse a raw GameStatePayloadBuilder JSON object into a typed
 * GameStatePayload, decoding the base64 board and overriding the board's
 * binary flags with the authoritative JSON verdict.
     */
    parsePayload(data: {board?: string; moves?: number[]; status?: string; endReason?: string; result?: string | null; gameOver?: boolean; whiteWins?: boolean; draw?: boolean; clock?: ClockState | null; serverTime?: number}): GameStatePayload {
        const boardBase64 = data.board ?? '';
        const binaryString = atob(boardBase64);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        const board = decodeBoardFromBinary(bytes);

        // Override the board's binary game-over flags with the authoritative
        // JSON verdict (resignation/timeout/abort never set the engine flag).
        if (typeof data.gameOver === 'boolean') {
            board.gameOver = data.gameOver;
        }
        if (typeof data.whiteWins === 'boolean') {
            board.whiteWins = data.whiteWins;
        }
        if (typeof data.draw === 'boolean') {
            board.draw = data.draw;
        }

        return {
            board,
            moves: data.moves ?? [],
            status: data.status ?? '',
            endReason: data.endReason ?? '',
            result: data.result ?? null,
            gameOver: data.gameOver ?? board.isGameOver(),
            whiteWins: data.whiteWins ?? board.whiteWins,
            draw: data.draw ?? board.draw,
            clock: data.clock ?? null,
            serverTime: data.serverTime ?? 0,
        };
    }

    /**
     * Get engine move for current board state (MCTS engine)
     */
    async getEngineMove(board: Board): Promise<Move> {
        const binary = encodeBoardToBinary(board);
        const response = await fetch(`${this.backendUrl}/engine-move`, {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: binary as BodyInit,
        });

        if (!response.ok) {
            throw new Error(`Server returned ${response.status}`);
        }

        const moveBuffer = await response.arrayBuffer();
        const moveArray = new Uint16Array(moveBuffer);
        const engineMoveU16 = moveArray[0];

        const from = engineMoveU16 & 0x7F;
        const to = (engineMoveU16 >> 7) & 0x7F;
        const unstack = ((engineMoveU16 >> 14) & 0x1) === 1;

        return {from, to, unstack};
    }

    /**
     * Replay a list of moves and get the final board state
     */
    async replayMoves(moves: Move[]): Promise<Board> {
        // Import the encoding function
        const binary = encodeMoveListToBinary(moves);
        
        const response = await fetch(`${this.backendUrl}/replay-moves`, {
            method: 'POST',
            headers: {'Content-Type': 'application/octet-stream'},
            body: binary as BodyInit,
        });

        if (!response.ok) {
            throw new Error(`Server returned ${response.status}`);
        }

        const boardBuffer = await response.arrayBuffer();
        return decodeBoardFromBinary(new Uint8Array(boardBuffer));
    }

    /**
     * Undo move by calling server endpoint
     */
    async undoMove(): Promise<string> {
        if (!this.gameUuid) {
            throw new Error('No game UUID available');
        }

        const response = await fetch(`/play/${this.gameUuid}/undo`, {
            method: 'POST',
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || 'Failed to undo move');
        }

        return response.text();
    }

    /**
     * Resign the current game via AJAX. Returns the finished game state
     * payload (the server publishes the Mercure update for the opponent).
     */
    async resign(): Promise<GameStatePayload> {
        if (!this.gameUuid) {
            throw new Error('No game UUID available');
        }

        const response = await fetch(`/play/${this.gameUuid}/resign`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Failed to resign');
        }

        return this.parsePayload(data);
    }
}
