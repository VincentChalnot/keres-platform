import {Board, Piece, PotentialMove} from '../models/types';
import {PIECE_VALUES} from '../models/materialDiff';
import {GameAPI} from '../network/GameAPI';

/** The king is worth the game: it outranks every other piece when several are attacked. */
const KING_VALUE = 100;
/** Candidate captures looked at per call, most valuable victim first (each undefended-or-not check is one `/api/moves` call). */
const MAX_CANDIDATES = 6;

/** A piece of the victim's side that an attacker can win material on. */
export interface Loss {
    square: number;
    piece: Piece;
    /** `true`: nothing of the victim's recaptures there; `false`: attacked by a cheaper piece (the trade still loses). */
    undefended: boolean;
}

function typeValue(type: string): number {
    return 'king' === type ? KING_VALUE : (PIECE_VALUES[type] ?? 0);
}

/** A stack is worth both of its pieces. */
export function pieceValue(piece: Piece): number {
    return typeValue(piece.bottom) + (piece.top ? typeValue(piece.top) : 0);
}

/**
 * The most valuable piece of `victimWhite`'s side that `attacks` (the opponent's capture moves on `board`) wins material
 * on: it is left without a recapture, or it is taken by a cheaper piece. Null when no capture gains anything.
 * The attacker of a stack move that may unstack is taken to move its top piece alone (the cheaper, riskier option for it).
 * Pure position reading, same as the threat overlay: no rule beyond "can the victim's side move to that square next".
 */
export async function findLoss(api: GameAPI, board: Board, attacks: PotentialMove[], victimWhite: boolean): Promise<Loss | null> {
    const cheapest = new Map<number, {move: PotentialMove; value: number; unstack: boolean}>();

    for (const move of attacks) {
        const victim = board.getPieceAt(move.to);
        const attacker = board.getPieceAt(move.from);
        if (!victim || !attacker || victim.color !== victimWhite) continue;

        const unstack = (move.unstackable || move.force_unstack) && null !== attacker.top;
        const value = unstack ? typeValue(attacker.top as string) : pieceValue(attacker);
        const known = cheapest.get(move.to);
        if (!known || value < known.value) {
            cheapest.set(move.to, {move, value, unstack});
        }
    }

    const candidates = [...cheapest.entries()]
        .map(([square, entry]) => ({square, piece: board.getPieceAt(square) as Piece, ...entry}))
        .sort((a, b) => pieceValue(b.piece) - pieceValue(a.piece))
        .slice(0, MAX_CANDIDATES);

    for (const candidate of candidates) {
        if (candidate.value < pieceValue(candidate.piece)) {
            return {square: candidate.square, piece: candidate.piece, undefended: false};
        }

        // Equal or dearer attacker: only a free piece is a loss. Play the capture and ask whether the victim's side can retake.
        const attacker = board.getPieceAt(candidate.move.from) as Piece;
        const cells = [...board.cells];
        cells[candidate.move.to] = candidate.unstack ? {color: attacker.color, bottom: attacker.top as string, top: null} : attacker;
        cells[candidate.move.from] = candidate.unstack ? {color: attacker.color, bottom: attacker.bottom, top: null} : null;
        const retakes = await api.getPotentialMoves(new Board(cells, victimWhite));

        if (!retakes.some((move) => move.to === candidate.square)) {
            return {square: candidate.square, piece: candidate.piece, undefended: true};
        }
    }

    return null;
}
