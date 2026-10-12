import {GameState} from './models/GameState';
import {t} from './i18n';
import {algebraicToPos, parseBoardCode} from './utils/boardUtils';
import SVGBoardView from './views/SVGBoardView';

/**
 * The /tutorial page (templates/actions/tutorial.html.twig). Static slides first (images exported from the admin board
 * editor), then a guided problem played on the game's own `SVGBoardView` - no engine, no game controller: every step
 * of the problem is authored in `public/tutorial/puzzle.json` with the board code it leads to, so nothing about the
 * rules lives here (the platform stays game-agnostic: it only replays what the author recorded).
 */

type StepText = 'carry' | 'unstack_fork' | 'legal_blunder' | 'capture_king';

interface PuzzleFile {
    start: string;
    flipped?: boolean;
    steps: Array<{by: string; from: string; to: string; board: string; text: string}>;
}

interface Step {
    by: 'player' | 'opponent';
    from: number;
    to: number;
    board: Uint8Array;
    text: StepText;
}

interface Puzzle {
    start: Uint8Array;
    flipped: boolean;
    steps: Step[];
}

/** An opponent's reply is played this long after its sentence appears, so that it can be read. */
const OPPONENT_DELAY_MS = 900;

/** Literal keys (checked by FrontendCatalogueTest), resolved when needed because the catalogue is read lazily. */
const STEP_TEXTS: Record<StepText, () => string> = {
    carry: () => t('tutorial.puzzle.carry'),
    unstack_fork: () => t('tutorial.puzzle.unstack_fork'),
    legal_blunder: () => t('tutorial.puzzle.legal_blunder'),
    capture_king: () => t('tutorial.puzzle.capture_king'),
};

function element<T extends HTMLElement>(id: string): T {
    const found = document.getElementById(id);
    if (!found) {
        throw new Error(`Missing #${id}`);
    }

    return found as T;
}

/** The authored file, checked end to end: every board code, every square and every sentence. A string says what is wrong. */
function parsePuzzle(raw: unknown): Puzzle | string {
    const file = raw as Partial<PuzzleFile> | null;
    if (!file || 'string' !== typeof file.start || !Array.isArray(file.steps) || 0 === file.steps.length) {
        return 'a puzzle needs a "start" board code and a non-empty "steps" list';
    }
    const start = parseBoardCode(file.start);
    if ('error' in start) {
        return `start: ${start.error}`;
    }

    const steps: Step[] = [];
    for (const [number, step] of file.steps.entries()) {
        const where = `step ${number + 1}`;
        if ('player' !== step.by && 'opponent' !== step.by) {
            return `${where}: "by" is "player" or "opponent"`;
        }
        const from = 'string' === typeof step.from ? algebraicToPos(step.from) : null;
        const to = 'string' === typeof step.to ? algebraicToPos(step.to) : null;
        if (null === from || null === to || from === to) {
            return `${where}: "from" and "to" are two different squares such as "E2"`;
        }
        if (!(step.text in STEP_TEXTS)) {
            return `${where}: unknown "text" ${String(step.text)}`;
        }
        const board = 'string' === typeof step.board ? parseBoardCode(step.board) : {error: 'missing'};
        if ('error' in board) {
            return `${where}: board: ${board.error}`;
        }
        steps.push({by: step.by, from, to, board: board.bytes, text: step.text as StepText});
    }

    return {start: start.bytes, flipped: true === file.flipped, steps};
}

/** Slides: one at a time, Previous / Next, the arrow keys; the last "Next" starts the problem. */
function initSlides(onFinished: () => void): void {
    const slides = Array.from(document.querySelectorAll<HTMLElement>('[data-slide]'));
    const previous = element<HTMLButtonElement>('tutorial-prev');
    const next = element<HTMLButtonElement>('tutorial-next');
    const progress = element<HTMLElement>('tutorial-progress');
    const nextLabel = next.textContent ?? '';
    let current = 0;
    let finished = false;

    function show(index: number): void {
        current = index;
        slides.forEach((slide, i) => {
            slide.hidden = i !== index;
        });
        previous.disabled = 0 === index;
        next.textContent = index === slides.length - 1 ? t('tutorial.slide.to_puzzle') : nextLabel;
        progress.textContent = t('tutorial.slide.progress', {current: index + 1, total: slides.length});
    }

    function forward(): void {
        if (finished) {
            return;
        }
        if (current === slides.length - 1) {
            finished = true;
            element('tutorial-slides').hidden = true;
            onFinished();

            return;
        }
        show(current + 1);
    }

    function backward(): void {
        if (!finished && current > 0) {
            show(current - 1);
        }
    }

    previous.addEventListener('click', backward);
    next.addEventListener('click', forward);
    document.addEventListener('keydown', (event) => {
        if ('ArrowRight' === event.key) {
            forward();
        } else if ('ArrowLeft' === event.key) {
            backward();
        }
    });
    show(0);
}

/** The guided problem: the player makes the recorded moves, the opponent's replies play themselves. */
class GuidedPuzzle {
    private readonly gameState = new GameState();
    private readonly view = new SVGBoardView(this.gameState);
    private stepIndex = -1;
    private selected: number | null = null;
    private locked = true;
    // The view also calls the click callback when a drag starts or is cancelled (it "selects" the source tile);
    // those happen with the button down, a real click is reported after it is up (same guard as the board editor).
    private pointerDown = false;

    constructor(
        private readonly puzzle: Puzzle,
        private readonly container: HTMLElement,
        private readonly caption: HTMLElement,
        private readonly message: HTMLElement,
        private readonly onSolved: () => void,
    ) {
    }

    async start(): Promise<void> {
        this.gameState.setBoardFlipped(this.puzzle.flipped);
        await this.view.initialize(this.container);
        this.view.setAnimationSpeed('slow');
        this.view.setCoordinatesVisible(true);
        await this.view.render(this.puzzle.start, this.puzzle.flipped);

        this.view.onTileClick((index) => {
            if (!this.pointerDown) {
                this.click(index);
            }
        });
        this.view.onDragMove((from, to) => {
            // Moving the dragged piece's element before the browser dispatches the click would swallow the next real
            // click (see the board editor): answer from the next task.
            window.setTimeout(() => this.attempt(from, to), 0);
        });
        this.container.addEventListener('mousedown', (event) => {
            if (0 === event.button) {
                this.pointerDown = true;
            }
        });
        this.container.addEventListener('touchstart', () => {
            this.pointerDown = true;
        }, {passive: true});
        const release = (): void => {
            this.pointerDown = false;
        };
        window.addEventListener('mouseup', release);
        window.addEventListener('touchend', release);
        window.addEventListener('touchcancel', release);

        await this.next();
    }

    private get step(): Step | undefined {
        return this.puzzle.steps[this.stepIndex];
    }

    /** Shows the next step's sentence and either waits for the player or plays the reply. */
    private async next(): Promise<void> {
        this.stepIndex++;
        this.selected = null;
        this.say('');
        this.view.updateOverlays([]);
        this.view.setArrows([]);

        const step = this.step;
        if (!step) {
            this.locked = true;
            this.onSolved();

            return;
        }

        this.caption.textContent = STEP_TEXTS[step.text]();

        if ('player' === step.by) {
            this.view.setArrows([{from: step.from, to: step.to, color: 'green'}]);
            this.locked = false;

            return;
        }

        this.locked = true;
        await new Promise<void>((resolve) => window.setTimeout(resolve, OPPONENT_DELAY_MS));
        await this.play(step);
    }

    private async play(step: Step): Promise<void> {
        this.locked = true;
        this.view.updateOverlays([]);
        this.view.setArrows([]);
        await this.view.render(step.board, this.puzzle.flipped);
        await this.view.whenSettled();
        await this.next();
    }

    private click(index: number): void {
        const step = this.step;
        if (this.locked || !step) {
            return;
        }

        if (null === this.selected) {
            if (index !== step.from) {
                this.say(t('tutorial.puzzle.try_again'));

                return;
            }
            this.selected = index;
            this.say('');
            this.view.updateOverlays([{position: step.from, type: 'selected'}, {position: step.to, type: 'potential'}]);

            return;
        }

        if (index === step.to) {
            void this.play(step);

            return;
        }

        // Clicking the selected piece again puts it back; anything else is a wrong answer
        this.selected = null;
        this.view.updateOverlays([]);
        this.say(index === step.from ? '' : t('tutorial.puzzle.try_again'));
    }

    private attempt(from: number, to: number): void {
        const step = this.step;
        if (this.locked || !step) {
            return;
        }

        if (from === step.from && to === step.to) {
            void this.play(step);

            return;
        }

        this.selected = null;
        this.view.updateOverlays([]);
        this.say(t('tutorial.puzzle.try_again'));
    }

    private say(text: string): void {
        this.message.textContent = text;
    }
}

async function runPuzzle(root: HTMLElement): Promise<void> {
    const section = element('tutorial-puzzle');
    const caption = element('tutorial-caption');
    const message = element('tutorial-message');
    section.hidden = false;

    const finish = (): void => {
        void fetch('/onboarding/complete', {method: 'POST'}).catch(() => undefined);
        const end = element('tutorial-end');
        end.hidden = false;
        end.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    };

    try {
        const response = await fetch(root.dataset.puzzleUrl ?? '');
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const puzzle = parsePuzzle(await response.json());
        if ('string' === typeof puzzle) {
            throw new Error(puzzle);
        }

        await new GuidedPuzzle(puzzle, element('tutorial-board'), caption, message, finish).start();
    } catch (error) {
        console.error('Tutorial puzzle:', error);
        caption.textContent = t('tutorial.puzzle.invalid');
        // Never leave the visitor stuck on a broken problem
        finish();
    }
}

const root = document.getElementById('tutorial');
if (root) {
    initSlides(() => void runPuzzle(root));
}
