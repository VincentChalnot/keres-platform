import {GameState} from './models/GameState';
import {GameAPI} from './network/GameAPI';
import {GuestGameRecord, LocalGameAPI} from './network/LocalGameAPI';
import {request} from './network/LobbyAPI';
import SVGBoardView from './views/SVGBoardView';
import {GameController} from './controllers/GameController';
import {IBoardView} from './views/IBoardView';
import {ClockState} from './network/MercureClient';
import {decodeMoveListFromBase64, algebraicToPos, posToAlgebraic} from './utils/boardUtils';
import {computeMaterialDiff, renderMaterialHTML} from './models/materialDiff';
import {pieceRule} from './models/pieceRules';
import {alertModal, confirmModal} from './utils/modal';
import {PageFullscreen} from './utils/pageFullscreen';
import {EvalBar, formatEvaluation} from './views/EvalBar';
import {describeGameOver, gameOverOutcome} from './utils/gameOverText';
import {t} from './i18n';

/** Display defaults of a visitor without an account (guest games, anonymous spectators); signed-in players keep theirs in Settings. */
const BOARD_DEFAULTS_KEY = 'keres.boardDefaults';
const OPPONENT_TYPE_AI = 0;
const OPPONENT_TYPE_HOTSEAT = 1;
const OPPONENT_TYPE_MULTIPLAYER = 2;

/** Server timestamps in the game-state payload are Uu-format microseconds. */
const CLOCK_TICK_MS = 250;

type Side = 'white' | 'black';
const SIDES: Side[] = ['white', 'black'];

/** How a new game opens: the in-page switches start from these, and "Save as my defaults" writes them. */
interface BoardDefaults {
    showCoordinates: boolean;
    showThreats: boolean;
    rotateOpponentPieces: boolean;
}

/** One player card (app.scss `.player-card`): which of the two sits on top follows the board orientation. */
interface PlayerCard {
    root: HTMLElement;
    clock: HTMLElement;
    material: HTMLElement;
}

/** Formats milliseconds remaining as a clock face, day-aware for correspondence. */
function formatClockMs(ms: number): string {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (value: number): string => String(value).padStart(2, '0');

    if (days > 0) return t('play.timer.days_hours', {days, hours: pad(hours)});
    if (hours > 0) return `${hours}:${pad(minutes)}:${pad(seconds)}`;

    return `${minutes}:${pad(seconds)}`;
}

/** Payload of the `showUnstackModal` event dispatched by GameController: which buttons to offer. */
interface UnstackModalDetail {
    full: boolean;
    top: boolean;
    selectInstead: boolean;
}

interface GameStateBootstrap {
    clock: ClockState | null;
    endReason: string;
    engineEndCode: number | null;
    result: string | null;
    gameOver: boolean;
    whiteWins: boolean;
    draw: boolean;
    serverTime: number;
    /** Stored engine evaluations, index = ply (null entries: not computed yet); null when they must not be shown. */
    evaluations?: Array<number | null> | null;
}

/**
 * Automation surface for driving a game without reverse-engineering
 * SVG tile geometry (used by Playwright/browser-console test agents).
 */
interface KeresDebugApi {
    listMoves(): Array<{
        from: string;
        to: string;
        fromPos: number;
        toPos: number;
        unstackable: boolean;
        forceUnstack: boolean;
    }>;
    playMove(from: string | number, to: string | number, unstack?: boolean): Promise<void>;
    getTurn(): string;
    isLocked(): boolean;
    isGameOver(): boolean;
}

declare global {
    interface Window {
        __keresDebug?: KeresDebugApi;
    }
}

/**
 * Main application entry point
 */
class KeresGame {
    private gameState: GameState;
    private api!: GameAPI;
    private view!: IBoardView;
    private controller!: GameController;

    // DOM elements
    private boardContainer: HTMLElement;
    private gameLayout: HTMLElement;
    private gameStatusBanner: HTMLElement;
    private unstackModal: HTMLDivElement;
    private moveStackBtn: HTMLButtonElement;
    private moveUnstackBtn: HTMLButtonElement;
    private selectInsteadBtn: HTMLButtonElement;
    private unstackModalTitle: HTMLElement;
    private unstackModalText: HTMLElement;
    private moveList: HTMLElement;
    private moveHistoryBody: HTMLOListElement;
    private firstMoveBtn: HTMLButtonElement;
    private prevMoveBtn: HTMLButtonElement;
    private nextMoveBtn: HTMLButtonElement;
    private lastMoveBtn: HTMLButtonElement;
    private gameActions: HTMLElement | null;
    private undoBtn: HTMLButtonElement | null;
    private resignBtn: HTMLButtonElement | null;
    private feedbackBtn: HTMLButtonElement | null;
    private feedbackModal: HTMLDivElement;
    private feedbackModalBody: HTMLElement;
    private feedbackModalClose: HTMLButtonElement;
    private settingsBtn: HTMLButtonElement;
    private settingsMenu: HTMLElement;
    private coordsSwitch: HTMLInputElement;
    private threatsSwitch: HTMLInputElement;
    private rotateSwitch: HTMLInputElement;
    private saveDefaultsBtn: HTMLButtonElement;
    private saveDefaultsStatus: HTMLElement;
    private pieceInfoBody: HTMLElement;
    private cards: Record<Side, PlayerCard>;
    private gameMode: number = 0; // opponent type as int
    private playerWhite: boolean = true; // true if player is white
    private spectator: boolean = false; // viewer is not a participant (public multiplayer view)
    private signedIn: boolean = false;
    private guestRecord: GuestGameRecord | null = null; // browser-only game without an account
    private coordsVisible: boolean = true;
    private opponentRotated: boolean = false;
    private clockTimer: number | null = null;
    // Piece the info panel currently describes ("square:piece"), so hovering the same piece does not rebuild it.
    private pieceInfoKey: string | null = null;

    // Engine evaluation (White's point of view), index = ply (moves played).
    // Seeded from the page bootstrap, then pushed by the server over Mercure
    // as the worker computes each one: the page never waits for the engine.
    private evalBar: EvalBar | null = null;
    private liveEvaluation: boolean = false;
    private evaluations: Map<number, number> = new Map();
    private evaluationsRequestedIn: 'live' | 'over' | null = null;

    constructor() {
        this.gameState = new GameState();

        // Get DOM elements
        this.boardContainer = document.getElementById('board-container') as HTMLElement;
        this.gameLayout = document.getElementById('game-layout') as HTMLElement;
        this.gameStatusBanner = document.getElementById('game-status-banner') as HTMLElement;
        this.unstackModal = document.getElementById('unstack-modal') as HTMLDivElement;
        this.moveStackBtn = document.getElementById('move-stack') as HTMLButtonElement;
        this.moveUnstackBtn = document.getElementById('move-unstack') as HTMLButtonElement;
        this.selectInsteadBtn = document.getElementById('select-instead') as HTMLButtonElement;
        this.unstackModalTitle = document.getElementById('unstack-modal-title') as HTMLElement;
        this.unstackModalText = document.getElementById('unstack-modal-text') as HTMLElement;
        this.moveList = document.getElementById('move-list') as HTMLElement;
        this.moveHistoryBody = document.getElementById('move-history-body') as HTMLOListElement;
        this.firstMoveBtn = document.getElementById('first-move-btn') as HTMLButtonElement;
        this.prevMoveBtn = document.getElementById('prev-move-btn') as HTMLButtonElement;
        this.nextMoveBtn = document.getElementById('next-move-btn') as HTMLButtonElement;
        this.lastMoveBtn = document.getElementById('last-move-btn') as HTMLButtonElement;
        this.gameActions = document.getElementById('game-actions');
        this.undoBtn = document.getElementById('undo-btn') as HTMLButtonElement | null;
        this.resignBtn = document.getElementById('resign-btn') as HTMLButtonElement | null;
        this.feedbackBtn = document.getElementById('feedback-btn') as HTMLButtonElement | null;
        this.feedbackModal = document.getElementById('feedback-modal') as HTMLDivElement;
        this.feedbackModalBody = document.getElementById('feedback-modal-body') as HTMLElement;
        this.feedbackModalClose = document.getElementById('feedback-modal-close') as HTMLButtonElement;
        this.settingsBtn = document.getElementById('board-settings-btn') as HTMLButtonElement;
        this.settingsMenu = document.getElementById('board-settings-menu') as HTMLElement;
        this.coordsSwitch = document.getElementById('setting-coords') as HTMLInputElement;
        this.threatsSwitch = document.getElementById('setting-threats') as HTMLInputElement;
        this.rotateSwitch = document.getElementById('setting-rotate') as HTMLInputElement;
        this.saveDefaultsBtn = document.getElementById('save-board-defaults-btn') as HTMLButtonElement;
        this.saveDefaultsStatus = document.getElementById('save-board-defaults-status') as HTMLElement;
        this.pieceInfoBody = document.getElementById('piece-info-body') as HTMLElement;
        this.cards = {
            white: this.playerCard('white'),
            black: this.playerCard('black'),
        };

        // Read game mode and player color from data attributes
        this.gameMode = parseInt(this.boardContainer.getAttribute('data-opponent-type') || '0', 10);
        this.playerWhite = (this.boardContainer.getAttribute('data-player-white') === 'true');
        this.spectator = (this.boardContainer.getAttribute('data-spectator') === 'true');

        // Evaluations are for signed-in players (guests and anonymous spectators never get the bar; the server refuses them too).
        const canEvaluate = this.boardContainer.getAttribute('data-can-evaluate') === 'true';
        this.signedIn = canEvaluate;
        this.liveEvaluation = canEvaluate && this.boardContainer.getAttribute('data-live-evaluation') === 'true';
        const evalRoot = document.getElementById('eval-bar');
        const evalWhite = document.getElementById('eval-bar-white');
        const evalLabel = document.getElementById('eval-bar-label');
        if (canEvaluate && evalRoot && evalWhite && evalLabel) {
            this.evalBar = new EvalBar(evalRoot, evalWhite, evalLabel, document.getElementById('board-with-eval'));
        }

        if (this.boardContainer.getAttribute('data-guest') === 'true') {
            this.guestRecord = this.resolveGuestRecord();
            if (this.guestRecord) {
                this.gameMode = this.guestRecord.opponentType;
                this.playerWhite = this.guestRecord.playerWhite;
                this.nameGuestPlayers(this.guestRecord);
            }
        }
    }

    private playerCard(side: Side): PlayerCard {
        return {
            root: document.getElementById(`player-card-${side}`) as HTMLElement,
            clock: document.getElementById(`clock-${side}`) as HTMLElement,
            material: document.getElementById(`material-${side}`) as HTMLElement,
        };
    }

    /** A guest game has no server-side players: the cards are named here ("You" / "AI (level 2)", or "Player 1" / "Player 2" in hot-seat). */
    private nameGuestPlayers(record: GuestGameRecord): void {
        for (const side of SIDES) {
            const card = this.cards[side].root;
            const name = card.querySelector('.player-card__name');
            const avatar = card.querySelector('.player-card__avatar');
            const mine = record.playerWhite === ('white' === side);
            if (!name || !avatar) continue;

            if (record.opponentType === OPPONENT_TYPE_HOTSEAT) {
                name.textContent = 'white' === side ? t('play.players.player_1') : t('play.players.player_2');
            } else if (mine) {
                name.textContent = t('play.players.you');
            } else {
                name.textContent = t('play.players.ai', {level: record.aiLevel ?? 1});
                name.classList.add('is-engine');
                avatar.innerHTML = '<i class="fa-solid fa-robot"></i>';
            }
        }
    }

    /**
     * Guest game set-up: `?new=ai|hotseat&side=white|black` (from the
     * "Play AI or hot-seat" form) starts a fresh game, otherwise the one
     * saved in this browser resumes. With neither, back to the form.
     */
    private resolveGuestRecord(): GuestGameRecord | null {
        const params = new URLSearchParams(window.location.search);
        const requested = params.get('new');

        if ('ai' === requested || 'hotseat' === requested) {
            const level = parseInt(params.get('level') || '1', 10);
            const record = LocalGameAPI.start(
                'ai' === requested ? OPPONENT_TYPE_AI : OPPONENT_TYPE_HOTSEAT,
                'black' !== params.get('side'),
                Number.isFinite(level) ? level : 1,
            );
            // A reload must resume this game, not start another one.
            window.history.replaceState(null, '', window.location.pathname);

            return record;
        }

        const saved = LocalGameAPI.load();
        if (!saved) {
            window.location.replace('/play/new');
        }

        return saved;
    }

    async initialize(): Promise<void> {
        const localApi = this.guestRecord ? new LocalGameAPI(this.guestRecord) : null;
        if (this.boardContainer.getAttribute('data-guest') === 'true' && !localApi) {
            return; // redirecting to the new-game form
        }

        // Load configuration
        this.api = localApi ?? new GameAPI();

        // Initialize view
        this.view = new SVGBoardView(this.gameState) as IBoardView;
        await this.view.initialize(this.boardContainer);

        // Initialize controller
        this.controller = new GameController(this.gameState, this.api, this.view, this.gameMode, this.playerWhite);
        this.controller.onInspect((position) => this.renderPieceInfo(position));

        // Seed the authoritative clock/result state from the page's initial
        // bootstrap (PlayAction) so the first render — before any Mercure
        // message arrives — already reflects the true Game entity state
        // instead of only the board binary's engine-only verdict.
        const bootstrap = this.readBootstrap();
        if (bootstrap) {
            this.controller.setInitialState(bootstrap.clock, bootstrap.endReason, bootstrap.engineEndCode, bootstrap.result, bootstrap.serverTime);
            bootstrap.evaluations?.forEach((value, ply) => {
                if (null !== value) {
                    this.evaluations.set(ply, value);
                }
            });
        }

        // Initialize Mercure for all game types
        const gameUuid = this.boardContainer.getAttribute('data-game-uuid');
        if (gameUuid) {
            this.controller.initializeMercure(gameUuid, (ply, evaluation) => this.onEvaluationReceived(ply, evaluation));
        }

        // Read moves from data-moves attribute (or this browser's saved guest game)
        const movesBase64 = this.boardContainer.getAttribute('data-moves') || '';
        const moves = localApi ? localApi.getMoves() : decodeMoveListFromBase64(movesBase64);
        await this.controller.setMoves(moves);
        if (bootstrap?.gameOver) {
            this.controller.applyStoredVerdict(bootstrap.whiteWins, bootstrap.draw);
        }

        if (localApi) {
            // The AI's replies arrive where a Mercure update would for a persisted game.
            localApi.onRemoteUpdate((update) => {
                void this.controller.applyRemoteUpdate(update).then(() => this.refreshUI());
            });
            const ending = await localApi.restoredEnding();
            if (ending) {
                await this.controller.applyRemoteUpdate({...ending, seq: moves.length, rating: null});
            }
            const board = this.gameState.getBoard();
            if (board) {
                localApi.requestAiMoveIfDue(board);
            }
            window.addEventListener('moveSubmitted', () => {
                const current = this.gameState.getBoard();
                if (current) {
                    localApi.requestAiMoveIfDue(current);
                }
            });
        }

        // In AI and multiplayer modes, orientation is a fixed per-player
        // setting: flip whenever the viewer plays Black, regardless of
        // whose turn it is or how many moves have been played.
        if ((this.gameMode === OPPONENT_TYPE_AI || this.gameMode === OPPONENT_TYPE_MULTIPLAYER) && !this.playerWhite) {
            await this.controller.flipBoard();
        }
        // In hotseat mode, determine orientation based on last move
        else if (this.gameMode === OPPONENT_TYPE_HOTSEAT && moves.length % 2 === 1) {
            // Odd number of moves means black just played, so show white's perspective
            await this.controller.flipBoard();
        }

        // The display switches start from the viewer's defaults; toggling them only affects this page.
        const defaults = this.readBoardDefaults();
        this.setCoordinatesVisible(defaults.showCoordinates);
        this.setShowThreats(defaults.showThreats);
        this.setOpponentRotated(defaults.rotateOpponentPieces);

        // Setup UI event listeners
        this.setupEventListeners();

        // Update UI
        this.refreshUI();

        // Live-ticking clocks: the server only pushes a new snapshot on
        // moves/Mercure updates, so extrapolate locally between them.
        this.clockTimer = window.setInterval(() => this.renderClocks(), CLOCK_TICK_MS);
        window.addEventListener('pagehide', () => {
            clearInterval(this.clockTimer ?? undefined);
        }, {once: true});

        // Automation hook: exposes just enough of the internal state/
        // controller for a Playwright agent (or manual console use) to
        // drive a game without reverse-engineering SVG tile coordinates.
        this.exposeDebugApi();
    }

    private readBootstrap(): GameStateBootstrap | null {
        const el = document.getElementById('game-state-bootstrap');
        const raw = el?.textContent;

        if (!raw) return null;

        try {
            return JSON.parse(raw) as GameStateBootstrap;
        } catch (error) {
            console.error('Malformed #game-state-bootstrap payload:', error);

            return null;
        }
    }

    /**
     * Signed-in players: their Settings -> Board & gameplay values, rendered
     * into the page. Everyone else: what "Save as my defaults" stored in this
     * browser, over the same built-in defaults.
     */
    private readBoardDefaults(): BoardDefaults {
        const defaults: BoardDefaults = {
            showCoordinates: this.boardContainer.getAttribute('data-show-coordinates') !== 'false',
            showThreats: this.boardContainer.getAttribute('data-show-threats') !== 'false',
            rotateOpponentPieces: this.boardContainer.getAttribute('data-rotate-opponent') === 'true',
        };
        if (this.signedIn) return defaults;

        try {
            const stored: unknown = JSON.parse(window.localStorage.getItem(BOARD_DEFAULTS_KEY) ?? 'null');
            if (null !== stored && 'object' === typeof stored) {
                for (const key of Object.keys(defaults) as Array<keyof BoardDefaults>) {
                    const value = (stored as Record<string, unknown>)[key];
                    if ('boolean' === typeof value) defaults[key] = value;
                }
            }
        } catch {
            // Unreadable entry: the built-in defaults stand.
        }

        return defaults;
    }

    /**
     * Exposes a minimal automation surface on `window.__keresDebug` for
     * driving a game from a Playwright/browser-console agent without
     * needing to reverse-engineer SVG tile geometry.
     */
    private exposeDebugApi(): void {
        window.__keresDebug = {
            /** Legal moves for whoever's turn it currently is. */
            listMoves: () => this.gameState.getPotentialMoves().map((m) => ({
                from: posToAlgebraic(m.from),
                to: posToAlgebraic(m.to),
                fromPos: m.from,
                toPos: m.to,
                unstackable: m.unstackable,
                forceUnstack: m.force_unstack,
            })),
            /** Plays a move; `from`/`to` accept algebraic ("E3") or raw position ints. */
            playMove: async (from: string | number, to: string | number, unstack = false) => {
                const fromPos = typeof from === 'number' ? from : algebraicToPos(from);
                const toPos = typeof to === 'number' ? to : algebraicToPos(to);
                if (fromPos === null || toPos === null) {
                    throw new Error(`Invalid position: ${from} -> ${to}`);
                }
                await this.controller.playMove(fromPos, toPos, unstack);
            },
            getTurn: () => this.controller.getCurrentTurn(),
            isLocked: () => this.controller.isBoardLocked(),
            isGameOver: () => this.gameState.getBoard()?.isGameOver() ?? true,
        };
    }

    private setupEventListeners(): void {
        // Unstack modal buttons
        this.moveStackBtn.addEventListener('click', () => void this.handleMoveStack());
        this.moveUnstackBtn.addEventListener('click', () => this.handleMoveUnstack());
        this.selectInsteadBtn.addEventListener('click', () => this.handleSelectInstead());

        // Modal background close
        const modalBackground = this.unstackModal.querySelector('.modal-background');
        if (modalBackground) {
            modalBackground.addEventListener('click', () => this.handleModalClose());
        }

        // Game controls
        this.resignBtn?.addEventListener('click', () => void this.handleResign());
        this.undoBtn?.addEventListener('click', () => void this.handleUndo());
        this.feedbackBtn?.addEventListener('click', () => void this.openFeedbackModal());
        this.feedbackModalClose.addEventListener('click', () => this.closeFeedbackModal());
        this.feedbackModal.querySelector('.modal-background')?.addEventListener('click', () => this.closeFeedbackModal());

        // Move history: the list itself (one button per move) and the step buttons under it
        this.moveHistoryBody.addEventListener('click', (event) => {
            const move = (event.target as Element).closest<HTMLElement>('[data-ply]');
            if (move) {
                void this.controller.goToPly(Number(move.dataset.ply));
            }
        });
        this.firstMoveBtn.addEventListener('click', () => void this.controller.goToPly(0));
        this.prevMoveBtn.addEventListener('click', () => void this.controller.previousMove());
        this.nextMoveBtn.addEventListener('click', () => void this.controller.nextMove());
        this.lastMoveBtn.addEventListener('click', () => void this.controller.goToPly(this.controller.getTotalPlies()));

        // Panel tools: flip, fullscreen, display settings
        document.getElementById('flip-board-btn')?.addEventListener('click', () => void this.handleFlipBoard());
        new PageFullscreen(document.getElementById('toggle-fullscreen-btn') as HTMLButtonElement);
        this.settingsBtn.addEventListener('click', () => this.setSettingsOpen(this.settingsMenu.hidden));
        this.coordsSwitch.addEventListener('change', () => this.setCoordinatesVisible(this.coordsSwitch.checked));
        this.threatsSwitch.addEventListener('change', () => this.setShowThreats(this.threatsSwitch.checked));
        this.rotateSwitch.addEventListener('change', () => this.setOpponentRotated(this.rotateSwitch.checked));
        this.saveDefaultsBtn.addEventListener('click', () => void this.saveBoardDefaults());
        document.addEventListener('click', (event) => {
            if (!this.settingsMenu.hidden && !(event.target as Element).closest('.board-settings')) {
                this.setSettingsOpen(false);
            }
        });

        // Custom event for unstack / stack-confirmation modal
        window.addEventListener('showUnstackModal', (event) => {
            this.openUnstackModal((event as CustomEvent<UnstackModalDetail>).detail);
        });
        document.addEventListener('keydown', (event) => this.handleKeydown(event));

        // GameController reports failures through this instead of native alert().
        window.addEventListener('showError', (event) => {
            const detail = (event as CustomEvent<{message: string}>).detail;
            void alertModal(detail.message, t('common.error'));
        });

        // Custom event for board state changes (e.g., from browser history navigation)
        window.addEventListener('boardStateChanged', () => this.refreshUI());

        // Clock snapshot changed (move played, resign, Mercure update): re-render immediately
        // instead of waiting for the next tick.
        window.addEventListener('clockChanged', () => this.renderClocks());

        // Auto-rotate board in hotseat mode after each submitted move
        window.addEventListener('moveSubmitted', async () => {
            if (this.gameMode === OPPONENT_TYPE_HOTSEAT) {
                await this.controller.flipBoard();
                this.applyOrientation();
            }
        });
    }

    /**
     * Escape closes the open modal or menu; ←/→ step through the moves and
     * Home/End jump to the start/latest position - unless the key is meant for
     * a form field or a dialog is open.
     */
    private handleKeydown(event: KeyboardEvent): void {
        if ('Escape' === event.key) {
            if (this.unstackModal.classList.contains('is-active')) {
                this.handleModalClose();
            } else if (!this.settingsMenu.hidden) {
                this.setSettingsOpen(false);
                this.settingsBtn.focus();
            }
            return;
        }

        const target = event.target as HTMLElement;
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (target.closest('input, textarea, select, [contenteditable="true"], .modal.is-active')) return;
        if (document.querySelector('.modal.is-active')) return;

        const navigation: Record<string, () => Promise<void>> = {
            ArrowLeft: () => this.controller.previousMove(),
            ArrowRight: () => this.controller.nextMove(),
            Home: () => this.controller.goToPly(0),
            End: () => this.controller.goToPly(this.controller.getTotalPlies()),
        };
        const step = navigation[event.key];
        if (!step) return;

        event.preventDefault();
        void step();
    }

    private async handleMoveStack(unstack: boolean = false): Promise<void> {
        this.closeUnstackModal();
        const selectedPosition = this.gameState.getSelectedPosition();
        const clickedDestination = this.gameState.getClickedDestination();
        if (selectedPosition !== null && clickedDestination !== null) {
            await this.controller.playMove(selectedPosition, clickedDestination, unstack);
            this.refreshUI();
        }
    }

    private async handleMoveUnstack(): Promise<void> {
        await this.handleMoveStack(true);
    }

    private handleSelectInstead(): void {
        const target = this.gameState.getClickedDestination();
        this.closeUnstackModal();
        if (target !== null) {
            this.controller.selectPosition(target);
        }
    }

    private openUnstackModal(detail: UnstackModalDetail): void {
        const {full, top, selectInstead} = detail;
        if (selectInstead) {
            this.unstackModalTitle.textContent = t('play.stack_modal.select_title');
            this.unstackModalText.textContent = full && top
                ? t('play.stack_modal.select_text_full_top')
                : (top
                    ? t('play.stack_modal.select_text_top')
                    : t('play.stack_modal.select_text_stack'));
            this.moveStackBtn.textContent = top ? t('play.stack_modal.move_full_stack') : t('play.stack_modal.stack_here');
        } else {
            this.unstackModalTitle.textContent = t('play.stack_modal.choose_title');
            this.unstackModalText.textContent = t('play.stack_modal.choose_text');
            this.moveStackBtn.textContent = t('play.stack_modal.move_full_stack');
        }
        this.moveStackBtn.classList.toggle('is-hidden', !full);
        this.moveUnstackBtn.classList.toggle('is-hidden', !top);
        this.selectInsteadBtn.classList.toggle('is-hidden', !selectInstead);
        this.moveStackBtn.classList.toggle('is-primary', full);
        this.moveUnstackBtn.classList.toggle('is-primary', !full && top);
        this.unstackModal.classList.add('is-active');
        (full ? this.moveStackBtn : this.moveUnstackBtn).focus();
    }

    private closeUnstackModal(): void {
        this.unstackModal.classList.remove('is-active');
    }

    private handleModalClose(): void {
        this.closeUnstackModal();
        this.controller.clearSelectedMove();
    }

    /** Shows the board from the other side; any game, any viewer (it changes nothing but the view). */
    private async handleFlipBoard(): Promise<void> {
        await this.controller.flipBoard();
        this.applyOrientation();
    }

    /** The player cards and the evaluation bar follow the board: the side at the bottom of the board has its card at the bottom. */
    private applyOrientation(): void {
        const flipped = this.gameState.isBoardFlipped();
        this.gameLayout.classList.toggle('is-flipped', flipped);
        this.evalBar?.setFlipped(flipped);
    }

    private async handleResign(): Promise<void> {
        const confirmed = await confirmModal(
            t('play.resign.message'),
            {title: t('play.resign.title'), confirmLabel: t('play.resign.confirm'), danger: true},
        );

        if (!confirmed) return;

        await this.controller.resign();
        this.refreshUI();
    }

    private async openFeedbackModal(): Promise<void> {
        this.feedbackModal.classList.add('is-active');
        this.feedbackModalBody.innerHTML = '<progress class="progress is-small is-primary" max="100"></progress>';
        this.feedbackModalBody.firstElementChild!.textContent = t('common.loading');

        try {
            const response = await fetch('/feedback', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            this.feedbackModalBody.innerHTML = await response.text();
            this.wireFeedbackForm();
        } catch (error) {
            console.error('Failed to load feedback form:', error);
            this.feedbackModalBody.replaceChildren(this.feedbackMessage(t('play.feedback.load_failed')));
        }
    }

    private closeFeedbackModal(): void {
        this.feedbackModal.classList.remove('is-active');
    }

    private feedbackMessage(text: string): HTMLParagraphElement {
        const paragraph = document.createElement('p');
        paragraph.textContent = text;

        return paragraph;
    }

    private wireFeedbackForm(): void {
        const form = this.feedbackModalBody.querySelector('form');

        if (!(form instanceof HTMLFormElement)) return;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            void this.submitFeedbackForm(form);
        });
    }

    private async submitFeedbackForm(form: HTMLFormElement): Promise<void> {
        const formData = new FormData(form);

        try {
            // Always the fixed feedback route: the fetched fragment's <form
            // action=""> resolves against *this* page's URL, not /feedback.
            const response = await fetch('/feedback', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData,
            });
            const contentType = response.headers.get('content-type') ?? '';

            if (contentType.includes('application/json')) {
                const notification = document.createElement('div');
                notification.className = 'notification is-success';
                const strong = document.createElement('strong');
                strong.textContent = t('play.feedback.thanks_title');
                notification.append(strong, ` ${t('play.feedback.thanks_text')}`);
                this.feedbackModalBody.replaceChildren(notification);
            } else {
                // Validation failed: the server re-rendered the form with errors.
                this.feedbackModalBody.innerHTML = await response.text();
                this.wireFeedbackForm();
            }
        } catch (error) {
            console.error('Failed to submit feedback:', error);
            this.feedbackModalBody.replaceChildren(this.feedbackMessage(t('play.feedback.submit_failed')));
        }
    }

    private async handleUndo(): Promise<void> {
        await this.controller.undoMove();
        this.refreshUI();
    }

    // ─── Display settings (this page only, until saved as defaults) ───────────

    private setSettingsOpen(open: boolean): void {
        this.settingsMenu.hidden = !open;
        this.settingsBtn.setAttribute('aria-expanded', String(open));
        if (open) {
            this.saveDefaultsStatus.textContent = '';
        }
    }

    private setCoordinatesVisible(visible: boolean): void {
        this.coordsVisible = visible;
        this.coordsSwitch.checked = visible;
        this.view.setCoordinatesVisible?.(visible);
    }

    private setShowThreats(show: boolean): void {
        this.threatsSwitch.checked = show;
        if (this.controller.isShowThreats() !== show) {
            this.controller.toggleShowThreats();
        }
    }

    /** Turns the opponent's piece icons upside down (as seen from their side) or upright. */
    private setOpponentRotated(rotated: boolean): void {
        this.opponentRotated = rotated;
        this.rotateSwitch.checked = rotated;
        this.view.setOpponentRotated?.(rotated);
    }

    /** "Save as my defaults": the current switches become how every new game opens (the account for a signed-in player, this browser otherwise). */
    private async saveBoardDefaults(): Promise<void> {
        const defaults: BoardDefaults = {
            showCoordinates: this.coordsVisible,
            showThreats: this.controller.isShowThreats(),
            rotateOpponentPieces: this.opponentRotated,
        };
        this.saveDefaultsBtn.disabled = true;
        this.saveDefaultsStatus.textContent = '';

        try {
            if (this.signedIn) {
                await request<BoardDefaults>('/settings/board/defaults', 'POST', defaults);
            } else {
                window.localStorage.setItem(BOARD_DEFAULTS_KEY, JSON.stringify(defaults));
            }
            this.saveDefaultsStatus.textContent = t('play.settings.saved');
        } catch (error) {
            console.error('Failed to save the board defaults:', error);
            this.saveDefaultsStatus.textContent = t('play.settings.save_failed');
        } finally {
            this.saveDefaultsBtn.disabled = false;
        }
    }

    // ─── Piece info panel ─────────────────────────────────────────────────────

    /** Describes the piece or stack on `position` (selected, hovered or long-pressed), or explains how to get there. */
    private renderPieceInfo(position: number | null): void {
        const piece = null === position ? null : this.gameState.getBoard()?.getPieceAt(position) ?? null;
        const key = piece ? `${position}:${piece.color}:${piece.top}:${piece.bottom}` : '';
        if (key === this.pieceInfoKey) return;
        this.pieceInfoKey = key;

        const figure = document.createElement('div');
        figure.className = 'piece-info__figure';

        if (!piece) {
            // The empty figure keeps the panel at its full height, so hovering never makes the move list jump.
            const hint = document.createElement('p');
            hint.className = 'piece-info__hint';
            hint.textContent = t('play.piece_info.hint');
            this.pieceInfoBody.replaceChildren(figure, hint);
            return;
        }

        const colorClass = piece.color ? 'p-w' : 'p-b';
        // Same drawing as the board: the top piece of a stack sits 20 units above the bottom one.
        figure.innerHTML = `<svg viewBox="-5 ${piece.top ? -25 : -5} 110 ${piece.top ? 110 : 90}" xmlns="http://www.w3.org/2000/svg">`
            + `<use href="#piece-${piece.bottom}" class="piece ${colorClass}"/>`
            + (piece.top ? `<use href="#piece-${piece.top}" class="piece ${colorClass}" y="-20"/>` : '')
            + '</svg>';

        const details = document.createElement('div');
        details.className = 'piece-info__details';
        const layers: Array<[string, string | null]> = piece.top
            ? [[piece.top, t('play.piece_info.on_top')], [piece.bottom, t('play.piece_info.underneath')]]
            : [[piece.bottom, null]];
        for (const [type, layer] of layers) {
            const rule = pieceRule(type);
            if (!rule) continue;
            const name = document.createElement('p');
            name.className = 'piece-info__name';
            name.textContent = rule.name;
            if (layer) {
                const tag = document.createElement('span');
                tag.className = 'piece-info__layer';
                tag.textContent = layer;
                name.append(' ', tag);
            }
            const movement = document.createElement('ul');
            movement.className = 'piece-info__movement';
            for (const line of rule.movement.split('\n')) {
                const item = document.createElement('li');
                item.textContent = line;
                movement.appendChild(item);
            }
            details.append(name, movement);
        }

        this.pieceInfoBody.replaceChildren(figure, details);
    }

    /** Runs every UI refresh in lockstep so no call site can forget a piece of derived state. */
    private refreshUI(): void {
        this.updateStatus();
        this.updateMoveHistoryDisplay();
        this.updateNavigationButtons();
        this.updateMaterialDiff();
        this.updateButtonVisibility();
        this.updatePlayerTurn();
        this.applyOrientation();
        this.applyGameOverVisuals();
        this.renderClocks();
        this.updateEvaluation();
    }

    /**
     * The evaluation bar is shown for games created with the live
     * evaluation option (unrated only) and, whatever the game, once it is
     * over: that is the replay. A rated game in progress never shows it (the
     * server refuses to answer anyway).
     */
    private isEvaluationVisible(): boolean {
        if (!this.evalBar || !this.api.hasEvaluationEndpoint()) return false;

        return this.liveEvaluation || this.controller.isGameOver();
    }

    /**
     * Renders the bar from what is known; never fetches. Evaluations are
     * computed by a worker and pushed over Mercure (`onEvaluationReceived`),
     * so a position whose verdict is not in yet leaves the bar where it was,
     * marked as pending, until it arrives.
     */
    private updateEvaluation(): void {
        const bar = this.evalBar;
        if (!bar) return;

        // After an undo the plies beyond the shorter line may be played again differently.
        const total = this.controller.getTotalPlies();
        for (const cachedPly of [...this.evaluations.keys()]) {
            if (cachedPly > total) this.evaluations.delete(cachedPly);
        }

        const visible = this.isEvaluationVisible();
        bar.setVisible(visible);
        if (!visible) return;

        bar.setFlipped(this.gameState.isBoardFlipped());
        const ply = this.controller.getDisplayedPly();
        bar.setScore(0 === ply ? 0 : (this.evaluations.get(ply) ?? null));

        void this.requestMissingEvaluations(total);
    }

    /** One evaluation pushed by the server; plies are counted in moves played. */
    private onEvaluationReceived(ply: number, evaluation: number): void {
        this.evaluations.set(ply, evaluation);
        this.updateEvaluation();
        this.updateMoveHistoryDisplay();
    }

    /**
     * Every move of a live game is queued server-side when it is played, so
     * this only matters for what the page did not witness: positions without
     * a stored verdict at load (older games, a backlog), and everything once
     * the game is over (a rated game kept its evaluations hidden until then).
     * The answer is immediate (what is stored); the rest comes over Mercure.
     * Runs at most once per phase (in progress / over): asking again after
     * each move would only queue the search that is already queued.
     */
    private async requestMissingEvaluations(total: number): Promise<void> {
        const phase = this.controller.isGameOver() ? 'over' : 'live';
        if (this.evaluationsRequestedIn === phase) return;

        this.evaluationsRequestedIn = phase;

        let missing = false;
        for (let ply = 1; ply <= total; ply++) {
            if (!this.evaluations.has(ply)) {
                missing = true;
                break;
            }
        }
        if (!missing) return;

        try {
            const stored = await this.api.requestEvaluations();
            stored.forEach((value, ply) => {
                if (null !== value && !this.evaluations.has(ply)) {
                    this.evaluations.set(ply, value);
                }
            });
            this.updateEvaluation();
            this.updateMoveHistoryDisplay();
        } catch (error) {
            console.error('Failed to request the evaluations:', error);
        }
    }

    /**
     * The status line next to the player cards. Always visible: it says whose
     * turn it is (highlighted with the active-clock accent when it's the
     * viewer's), what the viewer is waiting for, or the final result.
     */
    private updateStatus(): void {
        const board = this.gameState.getBoard();

        if (!board) {
            this.setBanner(t('common.loading'), 'muted');
            return;
        }

        if (board.isGameOver()) {
            this.setBanner(this.describeGameOver(), 'game-over');
            return;
        }

        if (this.controller.canNavigateToNext()) {
            this.setBanner(t('play.banner.viewing_history'), 'muted');
            return;
        }

        if (this.spectator) {
            this.setBanner(t('play.banner.turn', {side: board.whiteToMove ? 'white' : 'black'}), 'muted');
            return;
        }

        if (this.gameMode === OPPONENT_TYPE_HOTSEAT) {
            this.setBanner(t('play.banner.turn', {side: board.whiteToMove ? 'white' : 'black'}), 'your-turn');
            return;
        }

        if (board.whiteToMove !== this.playerWhite) {
            this.setBanner(this.gameMode === OPPONENT_TYPE_AI ? t('play.banner.waiting_ai') : t('play.banner.waiting_opponent'), 'muted');
            return;
        }

        this.setBanner(t('play.banner.your_turn'), 'your-turn');
    }

    private setBanner(text: string, tone: 'muted' | 'your-turn' | 'game-over'): void {
        this.gameStatusBanner.textContent = text;
        this.gameStatusBanner.classList.remove('is-hidden', 'is-muted', 'is-your-turn', 'is-won', 'is-lost', 'is-drawn', 'is-neutral-result');
        if ('game-over' === tone) {
            this.gameStatusBanner.classList.add(`is-${this.gameOverOutcome()}`);
        } else {
            this.gameStatusBanner.classList.add('muted' === tone ? 'is-muted' : 'is-your-turn');
        }
    }

    /** Banner colour class suffix: won (green), lost (red), drawn (orange), or a neutral result for spectators/hot-seat. */
    private gameOverOutcome(): 'won' | 'lost' | 'drawn' | 'neutral-result' {
        const viewer = this.spectator || this.gameMode === OPPONENT_TYPE_HOTSEAT ? null : (this.playerWhite ? 'white' : 'black');
        const outcome = gameOverOutcome(this.controller.getEndReason(), this.controller.getResult(), viewer);

        return 'neutral' === outcome ? 'neutral-result' : outcome;
    }

    private describeGameOver(): string {
        const text = describeGameOver(this.controller.getEndReason(), this.controller.getEngineEndCode(), this.controller.getResult());

        // Fallback for the rare case the authoritative endReason/result
        // hasn't arrived yet - the board binary's own engine verdict.
        return text ?? (this.gameState.getBoard()?.getGameResult() || t('play.banner.game_over'));
    }

    /** Saturates + disables all pointer interaction on the board once the game ends. */
    private applyGameOverVisuals(): void {
        this.boardContainer.classList.toggle('game-over', this.controller.isGameOver());
    }

    private updateButtonVisibility(): void {
        const over = this.controller.isGameOver();
        const multiplayer = this.gameMode === OPPONENT_TYPE_MULTIPLAYER;

        // No undo in multiplayer (yet - will be made configurable later);
        // and never once a game has ended, in any mode.
        this.undoBtn?.classList.toggle('is-hidden', multiplayer || over);
        this.resignBtn?.classList.toggle('is-hidden', over);
        this.gameActions?.classList.toggle('is-hidden', over);
    }

    /** Marks the card of the side to move in the game (not in the position being replayed). White moves first. */
    private updatePlayerTurn(): void {
        const over = this.controller.isGameOver();
        const toMove: Side = 0 === this.controller.getTotalPlies() % 2 ? 'white' : 'black';
        for (const side of SIDES) {
            this.cards[side].root.classList.toggle('is-to-move', !over && side === toMove);
        }
    }

    /** One button per move, two per row (White, Black); the displayed position's move is highlighted and kept in view. */
    private updateMoveHistoryDisplay(): void {
        const history = this.controller.getMoveHistory();
        const displayed = this.controller.getDisplayedPly();
        const rows: HTMLLIElement[] = [];
        let current: HTMLElement | null = null;

        for (let i = 0; i < history.length; i += 2) {
            const row = document.createElement('li');
            row.className = 'move-list__row';

            const number = document.createElement('span');
            number.className = 'move-list__number';
            number.textContent = `${i / 2 + 1}.`;
            row.appendChild(number);

            for (const ply of [i + 1, i + 2]) {
                const notation = history[ply - 1];
                if (!notation) break;
                const move = document.createElement('button');
                move.type = 'button';
                move.className = 'move-list__move';
                move.dataset.ply = String(ply);
                move.textContent = notation;
                this.appendMoveEvaluation(move, ply);
                if (ply === displayed) {
                    move.classList.add('is-current');
                    move.setAttribute('aria-current', 'step');
                    current = move;
                }
                row.appendChild(move);
            }

            rows.push(row);
        }

        if (0 === rows.length) {
            const empty = document.createElement('li');
            empty.className = 'move-list__empty';
            empty.textContent = t('play.history.empty');
            rows.push(empty);
        }

        this.moveHistoryBody.replaceChildren(...rows);

        // Scroll the list (never the page) just enough to show the current move.
        if (current) {
            const top = current.offsetTop;
            const bottom = top + current.offsetHeight;
            if (top < this.moveList.scrollTop) {
                this.moveList.scrollTop = top;
            } else if (bottom > this.moveList.scrollTop + this.moveList.clientHeight) {
                this.moveList.scrollTop = bottom - this.moveList.clientHeight;
            }
        }
    }

    private appendMoveEvaluation(cell: HTMLElement, ply: number): void {
        const value = this.isEvaluationVisible() ? this.evaluations.get(ply) : undefined;
        if (undefined === value) return;

        const span = document.createElement('span');
        span.className = 'move-eval';
        span.textContent = formatEvaluation(value);
        cell.appendChild(span);
    }

    private updateNavigationButtons(): void {
        const back = !this.controller.canNavigateToPrevious();
        const forward = !this.controller.canNavigateToNext();
        this.firstMoveBtn.disabled = back;
        this.prevMoveBtn.disabled = back;
        this.nextMoveBtn.disabled = forward;
        this.lastMoveBtn.disabled = forward;
    }

    /**
     * Each side's material edge on its own card: the piece types it has more
     * of, and the +N score on the side that is ahead.
     */
    private updateMaterialDiff(): void {
        const board = this.gameState.getBoard();
        if (!board) return;

        const diff = computeMaterialDiff(board);
        // scoreDelta > 0 → white is ahead; < 0 → black is ahead.
        this.cards.white.material.innerHTML = renderMaterialHTML(diff.whiteExcess, Math.max(0, diff.scoreDelta), 'p-b');
        this.cards.black.material.innerHTML = renderMaterialHTML(diff.blackExcess, Math.max(0, -diff.scoreDelta), 'p-w');
    }

    /**
     * Live-ticking player clocks, one per player card. Extrapolates forward
     * from the last server snapshot using the local/server clock skew captured
     * alongside it, so the running side counts down smoothly between updates.
     */
    private renderClocks(): void {
        const clock = this.controller.getClock();
        const timed = null !== clock && 'unlimited' !== clock.kind;

        for (const side of SIDES) {
            this.cards[side].clock.hidden = !timed;
        }
        if (!clock || !timed) return;

        const snapshot = this.controller.getClockSnapshot();
        const estimatedServerNowMs = snapshot.serverTimeMs + (Date.now() - snapshot.receivedAtMs);
        const remaining: Record<Side, number> = {white: clock.whiteMs ?? 0, black: clock.blackMs ?? 0};

        if ((clock.running === 'white' || clock.running === 'black') && null !== clock.turnStartedAt) {
            const elapsedMs = Math.max(0, estimatedServerNowMs - clock.turnStartedAt / 1000);
            remaining[clock.running] = Math.max(0, remaining[clock.running] - elapsedMs);
        }

        const gameOver = this.controller.isGameOver();
        for (const side of SIDES) {
            const el = this.cards[side].clock;
            const active = !gameOver && clock.running === side;
            const timeEl = el.querySelector('.player-clock__time');
            if (timeEl) {
                timeEl.textContent = formatClockMs(remaining[side]);
            }
            el.classList.toggle('is-active', active);
            el.classList.toggle('is-inactive', !active);
        }
    }
}

// Initialize the game when DOM is ready
new KeresGame().initialize();
