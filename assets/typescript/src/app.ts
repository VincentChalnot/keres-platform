import {GameState} from './models/GameState';
import {GameAPI} from './network/GameAPI';
import {GuestGameRecord, LocalGameAPI} from './network/LocalGameAPI';
import SVGBoardView from './views/SVGBoardView';
import {GameController} from './controllers/GameController';
import {IBoardView} from './views/IBoardView';
import {ClockState} from './network/MercureClient';
import {decodeMoveListFromBase64, algebraicToPos, posToAlgebraic} from './utils/boardUtils';
import {computeMaterialDiff, renderMaterialHTML} from './models/materialDiff';
import {alertModal, confirmModal} from './utils/modal';
import {PageFullscreen} from './utils/pageFullscreen';
import {EvalBar, formatEvaluation} from './views/EvalBar';
import {describeGameOver} from './utils/gameOverText';

const OPPONENT_TYPE_AI = 0;
const OPPONENT_TYPE_HOTSEAT = 1;
const OPPONENT_TYPE_MULTIPLAYER = 2;

/** Server timestamps in the game-state payload are Uu-format microseconds. */
const CLOCK_TICK_MS = 250;

/** Formats milliseconds remaining as a clock face, day-aware for correspondence. */
function formatClockMs(ms: number): string {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (value: number): string => String(value).padStart(2, '0');

    if (days > 0) return `${days}d ${pad(hours)}h`;
    if (hours > 0) return `${hours}:${pad(minutes)}:${pad(seconds)}`;

    return `${minutes}:${pad(seconds)}`;
}

/** Payload of the `showUnstackModal` event dispatched by GameController. */
interface UnstackModalDetail {
    kind: 'unstack' | 'stack-onto';
    allowUnstack?: boolean;
    forceUnstack?: boolean;
}

interface GameStateBootstrap {
    clock: ClockState | null;
    endReason: string;
    engineEndCode: number | null;
    result: string | null;
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
    private gameStatusBanner: HTMLElement;
    private unstackModal: HTMLDivElement;
    private moveStackBtn: HTMLButtonElement;
    private moveUnstackBtn: HTMLButtonElement;
    private selectInsteadBtn: HTMLButtonElement;
    private unstackModalTitle: HTMLElement;
    private unstackModalText: HTMLElement;
    private unstackModalForceUnstack = false;
    private switchSidesBtn: HTMLButtonElement | null;
    private moveHistoryBody: HTMLTableSectionElement;
    private prevMoveBtn: HTMLButtonElement;
    private nextMoveBtn: HTMLButtonElement;
    private undoBtn: HTMLButtonElement;
    private resignBtn: HTMLButtonElement | null;
    private feedbackBtn: HTMLButtonElement | null;
    private feedbackModal: HTMLDivElement;
    private feedbackModalBody: HTMLElement;
    private feedbackModalClose: HTMLButtonElement;
    private askEngineBtn: HTMLButtonElement | null;
    private toggleThreatsBtn: HTMLButtonElement;
    private toggleCoordsBtn: HTMLButtonElement;
    private materialTop: HTMLElement | null;
    private materialBottom: HTMLElement | null;
    private clockTop: HTMLElement;
    private clockBottom: HTMLElement;
    private gameMode: number = 0; // opponent type as int
    private playerWhite: boolean = true; // true if player is white
    private spectator: boolean = false; // viewer is not a participant (public multiplayer view)
    private guestRecord: GuestGameRecord | null = null; // browser-only game without an account
    private coordsVisible: boolean = true;
    private clockTimer: ReturnType<typeof setInterval> | null = null;

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
        this.gameStatusBanner = document.getElementById('game-status-banner') as HTMLElement;
        this.unstackModal = document.getElementById('unstack-modal') as HTMLDivElement;
        this.moveStackBtn = document.getElementById('move-stack') as HTMLButtonElement;
        this.moveUnstackBtn = document.getElementById('move-unstack') as HTMLButtonElement;
        this.selectInsteadBtn = document.getElementById('select-instead') as HTMLButtonElement;
        this.unstackModalTitle = document.getElementById('unstack-modal-title') as HTMLElement;
        this.unstackModalText = document.getElementById('unstack-modal-text') as HTMLElement;
        this.switchSidesBtn = document.getElementById('switch-sides-btn') as HTMLButtonElement | null;
        this.moveHistoryBody = document.getElementById('move-history-body') as HTMLTableSectionElement;
        this.prevMoveBtn = document.getElementById('prev-move-btn') as HTMLButtonElement;
        this.nextMoveBtn = document.getElementById('next-move-btn') as HTMLButtonElement;
        this.undoBtn = document.getElementById('undo-btn') as HTMLButtonElement;
        this.resignBtn = document.getElementById('resign-btn') as HTMLButtonElement | null;
        this.feedbackBtn = document.getElementById('feedback-btn') as HTMLButtonElement | null;
        this.feedbackModal = document.getElementById('feedback-modal') as HTMLDivElement;
        this.feedbackModalBody = document.getElementById('feedback-modal-body') as HTMLElement;
        this.feedbackModalClose = document.getElementById('feedback-modal-close') as HTMLButtonElement;
        this.askEngineBtn = document.getElementById('ask-engine-btn') as HTMLButtonElement | null;
        this.toggleThreatsBtn = document.getElementById('toggle-threats-btn') as HTMLButtonElement;
        this.toggleCoordsBtn = document.getElementById('toggle-coords-btn') as HTMLButtonElement;
        this.materialTop = document.getElementById('material-top');
        this.materialBottom = document.getElementById('material-bottom');
        this.clockTop = document.getElementById('clock-top') as HTMLElement;
        this.clockBottom = document.getElementById('clock-bottom') as HTMLElement;

        // Read game mode and player color from data attributes
        this.gameMode = parseInt(this.boardContainer.getAttribute('data-opponent-type') || '0', 10);
        this.playerWhite = (this.boardContainer.getAttribute('data-player-white') === 'true');
        this.spectator = (this.boardContainer.getAttribute('data-spectator') === 'true');

        this.liveEvaluation = this.boardContainer.getAttribute('data-live-evaluation') === 'true';
        const evalRoot = document.getElementById('eval-bar');
        const evalWhite = document.getElementById('eval-bar-white');
        const evalLabel = document.getElementById('eval-bar-label');
        if (evalRoot && evalWhite && evalLabel) {
            this.evalBar = new EvalBar(evalRoot, evalWhite, evalLabel, document.getElementById('board-with-eval'));
        }

        if (this.boardContainer.getAttribute('data-guest') === 'true') {
            this.guestRecord = this.resolveGuestRecord();
            if (this.guestRecord) {
                this.gameMode = this.guestRecord.opponentType;
                this.playerWhite = this.guestRecord.playerWhite;
                if (this.gameMode !== OPPONENT_TYPE_AI) {
                    this.switchSidesBtn?.remove();
                    this.switchSidesBtn = null;
                }
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
            const record = LocalGameAPI.start(
                'ai' === requested ? OPPONENT_TYPE_AI : OPPONENT_TYPE_HOTSEAT,
                'black' !== params.get('side'),
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

        // Settings -> Board & gameplay: initial state of the two in-page toggles.
        if (this.boardContainer.getAttribute('data-show-coordinates') === 'false') {
            this.handleToggleCoords();
        }
        if (this.boardContainer.getAttribute('data-show-threats') === 'false' && this.controller.isShowThreats()) {
            this.controller.toggleShowThreats();
        }

        // Setup UI event listeners
        this.setupEventListeners();

        // Update UI
        this.refreshUI();

        // Live-ticking clocks: the server only pushes a new snapshot on
        // moves/Mercure updates, so extrapolate locally between them.
        this.clockTimer = setInterval(() => this.renderClocks(), CLOCK_TICK_MS);
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
        this.moveStackBtn.addEventListener('click', () => void this.handleModalConfirm());
        this.moveUnstackBtn.addEventListener('click', () => this.handleMoveUnstack());
        this.selectInsteadBtn.addEventListener('click', () => this.handleSelectInstead());

        // Modal background close
        const modalBackground = this.unstackModal.querySelector('.modal-background');
        if (modalBackground) {
            modalBackground.addEventListener('click', () => this.handleModalClose());
        }

        // Game controls
        if (this.switchSidesBtn) {
            this.switchSidesBtn.addEventListener('click', () => this.handleSwitchSides());
        }
        if (this.askEngineBtn) {
            this.askEngineBtn.addEventListener('click', () => this.handleAskEngine());
        }
        if (this.resignBtn) {
            this.resignBtn.addEventListener('click', () => void this.handleResign());
        }
        this.feedbackBtn?.addEventListener('click', () => void this.openFeedbackModal());
        this.feedbackModalClose.addEventListener('click', () => this.closeFeedbackModal());
        this.feedbackModal.querySelector('.modal-background')?.addEventListener('click', () => this.closeFeedbackModal());

        this.undoBtn.addEventListener('click', () => this.handleUndo());
        this.prevMoveBtn.addEventListener('click', () => this.handlePrevMove());
        this.nextMoveBtn.addEventListener('click', () => this.handleNextMove());
        this.toggleThreatsBtn.addEventListener('click', () => this.handleToggleThreats());
        this.toggleCoordsBtn.addEventListener('click', () => this.handleToggleCoords());
        new PageFullscreen(document.getElementById('toggle-fullscreen-btn') as HTMLButtonElement);

        // Custom event for unstack / stack-confirmation modal
        window.addEventListener('showUnstackModal', (event) => {
            this.openUnstackModal((event as CustomEvent<UnstackModalDetail | undefined>).detail);
        });
        document.addEventListener('keydown', (event) => {
            if ('Escape' === event.key && this.unstackModal.classList.contains('is-active')) {
                this.handleModalClose();
            }
        });

        // GameController reports failures through this instead of native alert().
        window.addEventListener('showError', (event) => {
            const detail = (event as CustomEvent<{message: string}>).detail;
            void alertModal(detail.message, 'Error');
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
                this.updateMaterialDiff();
                this.evalBar?.setFlipped(this.gameState.isBoardFlipped());
            }
        });
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

    /** Modal "confirm" button: plays the move as given by the potential move (stack mode) or as a full-stack move. */
    private async handleModalConfirm(): Promise<void> {
        await this.handleMoveStack(this.unstackModalForceUnstack);
    }

    private handleSelectInstead(): void {
        const target = this.gameState.getClickedDestination();
        this.closeUnstackModal();
        if (target !== null) {
            this.controller.selectPosition(target);
        }
    }

    private openUnstackModal(detail: UnstackModalDetail | undefined): void {
        const stackOnto = detail?.kind === 'stack-onto';
        const allowUnstack = !stackOnto || (detail?.allowUnstack ?? false);
        this.unstackModalForceUnstack = stackOnto && (detail?.forceUnstack ?? false);
        this.unstackModalTitle.textContent = stackOnto ? 'Stack your pieces?' : 'Choose your move';
        this.unstackModalText.textContent = stackOnto
            ? (allowUnstack
                ? 'You are moving onto one of your own pieces. Move the full stack, only the top piece, or select that piece instead?'
                : 'You are moving onto one of your own pieces. Do you want to stack onto it, or select that piece instead?')
            : 'You are moving a stacked piece. Do you want to move the full stack or only the top piece?';
        this.moveStackBtn.textContent = stackOnto && !allowUnstack ? 'Stack here' : 'Move Full Stack';
        this.moveUnstackBtn.hidden = !allowUnstack;
        this.selectInsteadBtn.hidden = !stackOnto;
        this.unstackModal.classList.add('is-active');
        this.moveStackBtn.focus();
    }

    private closeUnstackModal(): void {
        this.unstackModal.classList.remove('is-active');
    }

    private handleModalClose(): void {
        this.closeUnstackModal();
        this.controller.clearSelectedMove();
    }

    private async handleSwitchSides(): Promise<void> {
        await this.controller.flipBoard();
        this.updateMaterialDiff();
        this.evalBar?.setFlipped(this.gameState.isBoardFlipped());
        this.renderClocks();
    }

    private async handleAskEngine(): Promise<void> {
        try {
            if (this.askEngineBtn) {
                this.askEngineBtn.disabled = true;
                this.askEngineBtn.innerText = 'Thinking...';
            }
            await this.controller.requestEngineMove();
            this.refreshUI();
        } catch (error) {
            console.error('Error getting engine move:', error);
            this.gameStatusBanner.textContent = `Error: ${(error as Error).message}. engine may not be available.`;
        } finally {
            if (this.askEngineBtn) {
                this.askEngineBtn.disabled = false;
                this.askEngineBtn.innerText = 'Ask Engine';
            }
        }
    }

    private async handleResign(): Promise<void> {
        const confirmed = await confirmModal(
            'Are you sure you want to resign? The game will be lost.',
            {title: 'Resign', confirmLabel: 'Resign', danger: true},
        );

        if (!confirmed) return;

        await this.controller.resign();
        this.refreshUI();
    }

    private async openFeedbackModal(): Promise<void> {
        this.feedbackModal.classList.add('is-active');
        this.feedbackModalBody.innerHTML = '<progress class="progress is-small is-primary" max="100">Loading</progress>';

        try {
            const response = await fetch('/feedback', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            this.feedbackModalBody.innerHTML = await response.text();
            this.wireFeedbackForm();
        } catch (error) {
            console.error('Failed to load feedback form:', error);
            this.feedbackModalBody.innerHTML = '<p>Failed to load the feedback form. Please try again later.</p>';
        }
    }

    private closeFeedbackModal(): void {
        this.feedbackModal.classList.remove('is-active');
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
                this.feedbackModalBody.innerHTML = '<div class="notification is-success"><strong>Thank you for your feedback!</strong> We review every submission.</div>';
            } else {
                // Validation failed: the server re-rendered the form with errors.
                this.feedbackModalBody.innerHTML = await response.text();
                this.wireFeedbackForm();
            }
        } catch (error) {
            console.error('Failed to submit feedback:', error);
            this.feedbackModalBody.innerHTML = '<p>Failed to submit feedback. Please try again later.</p>';
        }
    }

    private async handleUndo(): Promise<void> {
        await this.controller.undoMove();
        this.refreshUI();
    }

    private async handlePrevMove(): Promise<void> {
        await this.controller.previousMove();
    }

    private async handleNextMove(): Promise<void> {
        await this.controller.nextMove();
    }

    private handleToggleThreats(): void {
        this.controller.toggleShowThreats();
        this.updateToggleThreatsButton();
    }

    private handleToggleCoords(): void {
        this.coordsVisible = !this.coordsVisible;
        if (this.view.setCoordinatesVisible) {
            this.view.setCoordinatesVisible(this.coordsVisible);
        }
        this.toggleCoordsBtn.innerText = this.coordsVisible ? 'Hide Coords' : 'Show Coords';
    }

    private updateToggleThreatsButton(): void {
        if (this.controller.isShowThreats()) {
            this.toggleThreatsBtn.innerText = 'Hide Threats';
        } else {
            this.toggleThreatsBtn.innerText = 'Show Threats';
        }
    }

    /** Runs every UI refresh in lockstep so no call site can forget a piece of derived state. */
    private refreshUI(): void {
        this.updateStatus();
        this.updateMoveHistoryDisplay();
        this.updateNavigationButtons();
        this.updateToggleThreatsButton();
        this.updateMaterialDiff();
        this.updateButtonVisibility();
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

    /** " (+1.5)" suffix for a move's cell in the history table, empty when unknown or hidden. */
    private moveEvaluationLabel(ply: number): string {
        const value = this.isEvaluationVisible() ? this.evaluations.get(ply) : undefined;

        return undefined === value ? '' : formatEvaluation(value);
    }

    /**
     * The status bar under the board. Always visible: it says whose turn
     * it is (highlighted with the active-clock accent when it's the
     * viewer's), what the viewer is waiting for, or the final result.
     */
    private updateStatus(): void {
        const board = this.gameState.getBoard();

        if (!board) {
            this.setBanner('Loading…', 'muted');
            return;
        }

        if (board.isGameOver()) {
            this.setBanner(this.describeGameOver(), 'game-over');
            if (this.askEngineBtn) this.askEngineBtn.disabled = true;
            return;
        }

        if (this.controller.canNavigateToNext()) {
            this.setBanner('Viewing history – go to the latest move to continue playing', 'muted');
            if (this.askEngineBtn) this.askEngineBtn.disabled = true;
            return;
        }

        if (this.spectator) {
            this.setBanner(board.whiteToMove ? 'White to move' : 'Black to move', 'muted');
            if (this.askEngineBtn) this.askEngineBtn.disabled = true;
            return;
        }

        if (this.gameMode === OPPONENT_TYPE_HOTSEAT) {
            this.setBanner(board.whiteToMove ? 'White to move' : 'Black to move', 'your-turn');
            if (this.askEngineBtn) this.askEngineBtn.disabled = false;
            return;
        }

        if (board.whiteToMove !== this.playerWhite) {
            this.setBanner(this.gameMode === OPPONENT_TYPE_AI ? 'Waiting for AI…' : 'Waiting for opponent…', 'muted');
            if (this.askEngineBtn) this.askEngineBtn.disabled = true;
            return;
        }

        this.setBanner('Your turn', 'your-turn');
        if (this.askEngineBtn) this.askEngineBtn.disabled = this.controller.isBoardLocked();
    }

    private setBanner(text: string, tone: 'muted' | 'your-turn' | 'game-over'): void {
        this.gameStatusBanner.textContent = text;
        this.gameStatusBanner.classList.remove('is-hidden', 'is-muted', 'is-your-turn', 'is-success', 'is-warning');
        if ('game-over' === tone) {
            this.gameStatusBanner.classList.add(null === this.controller.getResult() || 'draw' === this.controller.getResult() ? 'is-warning' : 'is-success');
        } else {
            this.gameStatusBanner.classList.add('muted' === tone ? 'is-muted' : 'is-your-turn');
        }
    }

    private describeGameOver(): string {
        const text = describeGameOver(this.controller.getEndReason(), this.controller.getEngineEndCode(), this.controller.getResult());

        // Fallback for the rare case the authoritative endReason/result
        // hasn't arrived yet - the board binary's own engine verdict.
        return text ?? (this.gameState.getBoard()?.getGameResult() || 'Game over.');
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
        this.undoBtn.classList.toggle('is-hidden', multiplayer || over);
        this.toggleThreatsBtn.classList.toggle('is-hidden', over);

        if (this.resignBtn) {
            this.resignBtn.classList.toggle('is-hidden', over);
        }
    }

    private updateMoveHistoryDisplay(): void {
        const history = this.controller.getMoveHistory();
        
        // Clear the table body
        this.moveHistoryBody.innerHTML = '';
        
        // Build rows with white and black moves
        for (let i = 0; i < history.length; i += 2) {
            const row = document.createElement('tr');
            
            // Move number
            const numCell = document.createElement('td');
            numCell.textContent = `${Math.floor(i / 2) + 1}.`;
            row.appendChild(numCell);
            
            // White move
            const whiteCell = document.createElement('td');
            whiteCell.textContent = history[i] || '';
            this.appendMoveEvaluation(whiteCell, i + 1);
            row.appendChild(whiteCell);
            
            // Black move
            const blackCell = document.createElement('td');
            blackCell.textContent = history[i + 1] || '';
            if (history[i + 1]) {
                this.appendMoveEvaluation(blackCell, i + 2);
            }
            row.appendChild(blackCell);
            
            this.moveHistoryBody.appendChild(row);
        }
    }

    private appendMoveEvaluation(cell: HTMLElement, ply: number): void {
        const label = this.moveEvaluationLabel(ply);
        if ('' === label) return;

        const span = document.createElement('span');
        span.className = 'move-eval';
        span.textContent = label;
        cell.appendChild(span);
    }

    private updateNavigationButtons(): void {
        this.prevMoveBtn.disabled = !this.controller.canNavigateToPrevious();
        this.nextMoveBtn.disabled = !this.controller.canNavigateToNext();
    }

    private updateMaterialDiff(): void {
        const board = this.gameState.getBoard();
        if (!board || !this.materialTop || !this.materialBottom) return;

        const diff = computeMaterialDiff(board);
        const flipped = this.gameState.isBoardFlipped();

        // Show each player's excess icons on their own side of the board.
        // The +N advantage label is shown only on the side that is ahead.
        // scoreDelta > 0 → white is ahead; < 0 → black is ahead.
        const whiteAdvantage = diff.scoreDelta > 0 ? diff.scoreDelta : 0;
        const blackAdvantage = diff.scoreDelta < 0 ? -diff.scoreDelta : 0;

        if (flipped) {
            // Flipped: white at top, black at bottom
            // white's excess icons sit on white's side (top) → white pieces: black icon on white bg
            // black's excess icons sit on black's side (bottom) → black pieces: white icon on black bg
            this.materialTop.innerHTML = renderMaterialHTML(diff.whiteExcess, whiteAdvantage, 'p-b');
            this.materialBottom.innerHTML = renderMaterialHTML(diff.blackExcess, blackAdvantage, 'p-w');
        } else {
            // Normal: black at top, white at bottom
            // black's excess icons sit on black's side (top) → black pieces: white icon on black bg
            // white's excess icons sit on white's side (bottom) → white pieces: black icon on white bg
            this.materialTop.innerHTML = renderMaterialHTML(diff.blackExcess, blackAdvantage, 'p-w');
            this.materialBottom.innerHTML = renderMaterialHTML(diff.whiteExcess, whiteAdvantage, 'p-b');
        }
    }

    /**
     * Live-ticking player clocks, chess.com-style: one per player-info-row,
     * on the right of the material bar. Extrapolates forward from the last
     * server snapshot using the local/server clock skew captured alongside
     * it, so the running side counts down smoothly between updates.
     */
    private renderClocks(): void {
        const clock = this.controller.getClock();

        if (!clock || 'unlimited' === clock.kind) {
            this.clockTop.style.display = 'none';
            this.clockBottom.style.display = 'none';
            return;
        }

        this.clockTop.style.display = '';
        this.clockBottom.style.display = '';

        const snapshot = this.controller.getClockSnapshot();
        const estimatedServerNowMs = snapshot.serverTimeMs + (Date.now() - snapshot.receivedAtMs);

        let whiteMs = clock.whiteMs ?? 0;
        let blackMs = clock.blackMs ?? 0;

        if (clock.running && null !== clock.turnStartedAt) {
            const turnStartedAtMs = clock.turnStartedAt / 1000;
            const elapsedMs = Math.max(0, estimatedServerNowMs - turnStartedAtMs);
            if ('white' === clock.running) {
                whiteMs = Math.max(0, whiteMs - elapsedMs);
            } else if ('black' === clock.running) {
                blackMs = Math.max(0, blackMs - elapsedMs);
            }
        }

        const gameOver = this.controller.isGameOver();
        const flipped = this.gameState.isBoardFlipped();
        const topColor = flipped ? 'white' : 'black';
        const bottomColor = flipped ? 'black' : 'white';

        this.setClockDisplay(this.clockTop, flipped ? whiteMs : blackMs, !gameOver && clock.running === topColor);
        this.setClockDisplay(this.clockBottom, flipped ? blackMs : whiteMs, !gameOver && clock.running === bottomColor);
    }

    private setClockDisplay(el: HTMLElement, ms: number, active: boolean): void {
        const timeEl = el.querySelector('.player-clock__time');
        if (timeEl) {
            timeEl.textContent = formatClockMs(ms);
        }
        el.classList.toggle('is-active', active);
        el.classList.toggle('is-inactive', !active);
    }
}

// Initialize the game when DOM is ready
new KeresGame().initialize();
