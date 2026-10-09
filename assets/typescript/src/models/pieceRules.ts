/**
 * Piece movement rules for display in UI (names and descriptions are translated, see `play.piece.*`)
 */
import {t} from '../i18n';

export interface PieceRule {
    name: string;
    movement: string;
}

/** The piece types of `PIECE_CODE` plus the king, which has no code of its own. */
const PIECE_TYPES: Record<string, true> = {
    soldier: true,
    bishop: true,
    rook: true,
    paladin: true,
    guard: true,
    knight: true,
    ballista: true,
    king: true,
};

/** Name and movement description (lines separated by `\n`) of a piece type, or null for an unknown type. */
export function pieceRule(type: string): PieceRule | null {
    if (!PIECE_TYPES[type]) return null;

    return {
        name: t(`play.piece.${type}.name`),
        movement: t(`play.piece.${type}.movement`),
    };
}
