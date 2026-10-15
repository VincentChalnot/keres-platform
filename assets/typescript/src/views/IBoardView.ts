import type {AnimationSpeed} from '../models/types';
import type {BoardArrow} from './BoardArrows';

/**
 * Interface for board rendering
 * This abstraction allows switching between different rendering engines (2D sprites, 3D, etc.)
 */
export interface IBoardView {
    /**
     * Initialize the rendering system
     */
    initialize(container: HTMLElement): Promise<void>;

    /**
     * Render the board with all pieces
     */
    render(boardData: Uint8Array, flipped: boolean): Promise<void>;

    /**
     * Update tile overlays (selected, hovered, potential moves)
     */
    updateOverlays(highlights: TileHighlight[]): void;

    /**
     * Handle window resize
     */
    onResize(): void;

    /**
     * Clean up resources
     */
    dispose(): void;

    /**
     * Set click handler
     */
    onTileClick(handler: (tileIndex: number, shiftKey?: boolean) => void): void;

    /**
     * Set hover handler
     */
    onTileHover(handler: (tileIndex: number | null) => void): void;

    /**
     * Set drag move handler (optional - for drag & drop support)
     */
    onDragMove?(handler: (from: number, to: number, shiftKey?: boolean) => void): void;

    /**
     * Set the handler of a long press on a piece: inspect it without selecting it (optional)
     */
    onPieceLongPress?(handler: (tileIndex: number) => void): void;

    /**
     * Toggle coordinate labels visibility (optional)
     */
    setCoordinatesVisible?(visible: boolean): void;

    /**
     * Draw the opponent's (top side's) piece icons upside down when true, upright when false (optional)
     */
    setOpponentRotated?(rotated: boolean): void;

    /**
     * How fast a piece slides to its square when the displayed position moves on by one ply; 'instant' draws
     * the new position at once (optional)
     */
    setAnimationSpeed?(speed: AnimationSpeed): void;

    /**
     * Resolves once the piece movement started by the last render has finished (immediately when nothing moves) (optional)
     */
    whenSettled?(): Promise<void>;

    /**
     * Remove every analysis arrow (the ones drawn with the right mouse button). The view never clears them on its own:
     * whoever changes the displayed position calls this (optional)
     */
    clearArrows?(): void;

    /**
     * The analysis arrows as plain data, tiles indexed like `onTileClick` (optional)
     */
    getArrows?(): BoardArrow[];

    /**
     * Replace the analysis arrows (optional)
     */
    setArrows?(arrows: BoardArrow[]): void;

    /**
     * The board as currently displayed, as a complete standalone HTML document (board CSS + SVG, no script, no external
     * request). Admin tooling; never changes what the live board shows (optional)
     */
    exportStaticHtml?(title: string): string;

    /** The board as currently displayed, as a standalone SVG image (board CSS inside, no background). Admin tooling (optional) */
    exportStaticSvg?(): string;
}

export type PieceStyle = 'muted' | 'highlighted';

export interface TileHighlight {
    position: number;
    type: 'selected' | 'potential' | 'hovered' | 'threat' | 'last_move';
    /** Part of the picture, not of a mouse hover: kept by the static exports even for `hovered` (the board editor's paint) */
    lasting?: boolean;
}

/**
 * Interface for piece sprite loading
 */
export interface IPieceSpriteLoader {
    loadPieceSprite(pieceName: string, color: number, reversed: boolean): Promise<any>;
}
