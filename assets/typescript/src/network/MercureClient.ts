import {Board} from '../models/types';
import {decodeBoardFromBinary} from '../utils/boardUtils';

export interface RatingSide {
    before: number;
    after: number;
    delta: number;
}

export interface ClockState {
    kind: string;
    /** Milliseconds remaining on each side's clock (authoritative, from the Game entity). */
    whiteMs: number | null;
    blackMs: number | null;
    /** Which side's clock is running ('white' | 'black' | null when stopped/game over). */
    running: string | null;
    /** Server timestamp (microseconds) when the current turn started ticking. */
    turnStartedAt: number | null;
    /** Server timestamp (microseconds) of the move deadline. */
    deadlineAt: number | null;
}

export interface GameUpdate {
    seq: number;
    board: Board;
    moves: number[];
    status: string;
    endReason: string;
    /** 'white' | 'black' | 'draw' | null — authoritative result from the Game entity. */
    result: string | null;
    gameOver: boolean;
    whiteWins: boolean;
    draw: boolean;
    clock: ClockState | null;
    // null unless the game just finished rated (02-realtime.md sec 4.1)
    rating: {white: RatingSide; black: RatingSide} | null;
    serverTime: number;
}

/**
 * Client for receiving real-time game updates via Mercure
 */
export class MercureClient {
    private eventSource: EventSource | null = null;
    private lastSeq: number = 0;
    private mercureUrl: string;

    constructor() {
        // Get Mercure URL from environment or default
        this.mercureUrl = this.getMercureUrl();
    }

    /**
     * Get the Mercure hub URL from meta tag or construct from current location
     */
    private getMercureUrl(): string {
        // Try to get from meta tag first
        const metaTag = document.querySelector('meta[name="mercure-url"]');
        if (metaTag) {
            return metaTag.getAttribute('content') || '';
        }

        // Fallback to constructing from current location
        return `${window.location.protocol}//${window.location.host}/.well-known/mercure`;
    }

    /**
     * Subscribe to game updates for a specific game UUID
     */
    subscribe(gameUuid: string, onUpdate: (update: GameUpdate) => void): void {
        if (this.eventSource) {
            this.disconnect();
        }

        // Construct the Mercure subscription URL
        const topic = `game/${gameUuid}`;
        const url = new URL(this.mercureUrl);
        url.searchParams.append('topic', topic);

        console.log('Subscribing to Mercure:', url.toString());

        this.eventSource = new EventSource(url.toString());

        this.eventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                
                // Check seq to ignore out-of-order or stale updates
                if (data.seq !== undefined && data.seq <= this.lastSeq) {
                    console.log('Ignoring stale update seq=' + data.seq + ' last=' + this.lastSeq);
                    return;
                }

                this.lastSeq = data.seq ?? this.lastSeq;

                // Decode the board from base64
                const boardBase64 = data.board;
                const binaryString = atob(boardBase64);
                const bytes = new Uint8Array(binaryString.length);
                for (let i = 0; i < binaryString.length; i++) {
                    bytes[i] = binaryString.charCodeAt(i);
                }

                const board = decodeBoardFromBinary(bytes);

                // Decode moves if present
                let moves: number[] = [];
                if (data.moves) {
                    const movesBase64 = data.moves;
                    const movesBinaryString = atob(movesBase64);
                    const movesBytes = new Uint8Array(movesBinaryString.length);
                    for (let i = 0; i < movesBinaryString.length; i++) {
                        movesBytes[i] = movesBinaryString.charCodeAt(i);
                    }
                    // Convert to u16 array
                    const movesU16 = new Uint16Array(movesBytes.buffer);
                    moves = Array.from(movesU16);
                }

                // The board binary's game-over flags reflect only the engine's
                // verdict, which is absent on every non-engine finish
                // (resignation, timeout, abort). The JSON payload's fields are
                // authoritative — apply them onto the board so isGameOver()
                // matches the real Game entity state.
                this.applyAuthoritativeState(board, data);

                const update: GameUpdate = {
                    seq: data.seq ?? 0,
                    board: board,
                    moves: moves,
                    status: data.status ?? '',
                    endReason: data.endReason ?? '',
                    result: data.result ?? null,
                    gameOver: data.gameOver ?? board.isGameOver(),
                    whiteWins: data.whiteWins ?? board.whiteWins,
                    draw: data.draw ?? board.draw,
                    clock: data.clock ?? null,
                    rating: data.rating ?? null,
                    serverTime: data.serverTime ?? 0,
                };

                onUpdate(update);
            } catch (error) {
                console.error('Error processing Mercure update:', error);
            }
        };

        this.eventSource.onerror = (error) => {
            console.error('Mercure connection error:', error);
            // EventSource will automatically reconnect
        };

        this.eventSource.onopen = () => {
            console.log('Mercure connection opened');
        };
    }

    /**
     * Override the board's binary flags with the authoritative JSON fields.
     * The board's byte-81 flags only carry the engine's own verdict; for
     * resignation/timeout/abort the engine never runs, so the JSON payload
     * (read from the Game entity) is the single source of truth.
     */
    private applyAuthoritativeState(board: Board, data: {gameOver?: unknown; whiteWins?: unknown; draw?: unknown}): void {
        if (typeof data.gameOver === 'boolean') {
            board.gameOver = data.gameOver;
        }
        if (typeof data.whiteWins === 'boolean') {
            board.whiteWins = data.whiteWins;
        }
        if (typeof data.draw === 'boolean') {
            board.draw = data.draw;
        }
    }

    /**
     * Disconnect from Mercure
     */
    disconnect(): void {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    /**
     * Check if connected
     */
    isConnected(): boolean {
        return this.eventSource !== null && this.eventSource.readyState === EventSource.OPEN;
    }
}
