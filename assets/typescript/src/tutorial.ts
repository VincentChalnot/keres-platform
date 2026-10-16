import {GameState} from './models/GameState';
import {t} from './i18n';
import {GameAPI} from './network/GameAPI';
import {algebraicToPos, decodeBoardFromBinary, parseBoardCode} from './utils/boardUtils';
import SVGBoardView from './views/SVGBoardView';
import {PieceStyle, TileHighlight} from './views/IBoardView';
import {askUnstack, UnstackModalDetail} from './utils/unstackModal';

/**
 * The /tutorial page (templates/actions/tutorial.html.twig): the hybrid tutorial (D4 of docs/onboarding-plan.md) played
 * from `public/tutorial/lessons.json`. Two tracks - `chess` (short screens for chess players) and `beginner` (interactive
 * lessons on a live `SVGBoardView`) - made of lessons of four kinds:
 * - `read`: a sentence with static images and/or a board to look at;
 * - `reach`: a lone piece and "stars" (highlighted squares) to visit one after the other;
 * - `capture`: take the marked enemy pieces;
 * - `script`: authored steps (the player's moves, the opponent's replies, the real game's stack/unstack dialog).
 * No game rule lives here: the legal squares of `reach` / `capture` lessons are asked of the engine (`/api/moves`), the
 * boards of `script` lessons are recorded by the author. The client only moves a piece on the board bytes and hands the
 * turn back to the player (the side-to-move flag of the board is edited, nothing else). Every lesson is skippable.
 * Format reference: docs/features.md.
 */

/** The dialogs of the real game, by name: `choose` = full stack or top piece, `select*` = the move lands on one of the
 * player's own pieces (stack onto it, or select it instead), with the buttons the engine would allow. */
const PROMPTS = {
    choose: {full: true, top: true, selectInstead: false},
    select: {full: true, top: true, selectInstead: true},
    select_top: {full: false, top: true, selectInstead: true},
    select_stack: {full: true, top: false, selectInstead: true},
} satisfies Record<string, UnstackModalDetail>;

type PromptName = keyof typeof PROMPTS;
type Track = 'chess' | 'beginner';

interface ScriptStep {
    by: 'player' | 'opponent';
    from: number;
    to: number;
    board: Uint8Array;
    /** The move carries only the top piece of a stack (the engine's "unstack" flag). */
    unstack: boolean;
    /** The question asked when the player makes the move, or null for none. */
    prompt: PromptName | null;
    /** Catalogue key of the sentence shown while this step is awaited. */
    text: string;
}

type Goal =
    | {type: 'read'}
    | {type: 'reach'; stars: number[]; strict: boolean}
    | {type: 'capture'; targets: number[]}
    | {type: 'script'; steps: ScriptStep[]};

interface Lesson {
    id: string;
    track: Track;
    title: string;
    text: string | null;
    board: Uint8Array | null;
    flipped: boolean;
    images: string[];
    notes: string[];
    goal: Goal;
}

/** An opponent's reply is played this long after its sentence appears, so that it can be read. */
const OPPONENT_DELAY_MS = 900;
/** A `strict` lesson that went the wrong way starts over after this long. */
const RESTART_DELAY_MS = 1400;

/** White to move, no-capture counter 0: the only two things the client edits of a board. */
const FLAGS_INDEX = 81;
const WHITE_TO_MOVE = 0x80;

function element<T extends HTMLElement>(id: string): T {
    const found = document.getElementById(id);
    if (!found) {
        throw new Error(`Missing #${id}`);
    }

    return found as T;
}

function squares(value: unknown, where: string): number[] | string {
    if (!Array.isArray(value) || 0 === value.length) {
        return `${where}: a non-empty list of squares such as "E2"`;
    }
    const out: number[] = [];
    for (const square of value) {
        const position = 'string' === typeof square ? algebraicToPos(square) : null;
        if (null === position) {
            return `${where}: "${String(square)}" is not a square such as "E2"`;
        }
        out.push(position);
    }

    return out;
}

function board(value: unknown, where: string): Uint8Array | string {
    const parsed = 'string' === typeof value ? parseBoardCode(value) : {error: 'missing'};

    return 'error' in parsed ? `${where}: board: ${parsed.error}` : parsed.bytes;
}

function parseScript(raw: unknown, where: string): ScriptStep[] | string {
    if (!Array.isArray(raw) || 0 === raw.length) {
        return `${where}: a script needs a non-empty "steps" list`;
    }
    const steps: ScriptStep[] = [];
    for (const [number, step] of (raw as Array<Record<string, unknown>>).entries()) {
        const at = `${where} step ${number + 1}`;
        if ('player' !== step.by && 'opponent' !== step.by) {
            return `${at}: "by" is "player" or "opponent"`;
        }
        const from = 'string' === typeof step.from ? algebraicToPos(step.from) : null;
        const to = 'string' === typeof step.to ? algebraicToPos(step.to) : null;
        if (null === from || null === to || from === to) {
            return `${at}: "from" and "to" are two different squares such as "E2"`;
        }
        if ('string' !== typeof step.text || '' === step.text) {
            return `${at}: "text" is a catalogue key`;
        }
        const bytes = board(step.board, at);
        if ('string' === typeof bytes) {
            return bytes;
        }
        if (undefined !== step.unstack && 'boolean' !== typeof step.unstack) {
            return `${at}: "unstack" is true or false`;
        }
        let prompt: PromptName | null = null;
        if (undefined !== step.prompt) {
            if ('string' !== typeof step.prompt || !Object.prototype.hasOwnProperty.call(PROMPTS, step.prompt)) {
                return `${at}: unknown "prompt" ${String(step.prompt)} (${Object.keys(PROMPTS).join(', ')})`;
            }
            prompt = step.prompt as PromptName;
            if (true === step.unstack ? !PROMPTS[prompt].top : !PROMPTS[prompt].full) {
                return `${at}: the "${prompt}" prompt does not offer the answer the step expects`;
            }
        }
        steps.push({by: step.by, from, to, board: bytes, unstack: true === step.unstack, prompt, text: step.text});
    }

    return steps;
}

/** The authored file, checked end to end. A string says what is wrong. */
function parseLessons(raw: unknown): Lesson[] | string {
    const list = (raw as {lessons?: unknown} | null)?.lessons;
    if (!Array.isArray(list) || 0 === list.length) {
        return 'the file needs a non-empty "lessons" list';
    }
    const lessons: Lesson[] = [];
    for (const item of list as Array<Record<string, unknown>>) {
        const id = 'string' === typeof item.id ? item.id : '';
        const where = `lesson "${id}"`;
        if ('' === id) {
            return 'a lesson needs an "id"';
        }
        if ('chess' !== item.track && 'beginner' !== item.track) {
            return `${where}: "track" is "chess" or "beginner"`;
        }
        if ('string' !== typeof item.title || '' === item.title) {
            return `${where}: "title" is a catalogue key`;
        }
        if (undefined !== item.text && 'string' !== typeof item.text) {
            return `${where}: "text" is a catalogue key`;
        }
        let bytes: Uint8Array | null = null;
        if (undefined !== item.board) {
            const parsed = board(item.board, where);
            if ('string' === typeof parsed) {
                return parsed;
            }
            bytes = parsed;
        }
        const images = undefined === item.images ? [] : item.images;
        const notes = undefined === item.notes ? [] : item.notes;
        if (!Array.isArray(images) || images.some((image) => 'string' !== typeof image) || !Array.isArray(notes) || notes.some((note) => 'string' !== typeof note)) {
            return `${where}: "images" and "notes" are lists of strings`;
        }

        const goal = item.goal as Record<string, unknown> | undefined;
        let parsedGoal: Goal;
        switch (goal?.type) {
            case 'read':
                parsedGoal = {type: 'read'};
                break;
            case 'reach': {
                const stars = squares(goal.stars, `${where}: "stars"`);
                if ('string' === typeof stars) {
                    return stars;
                }
                parsedGoal = {type: 'reach', stars, strict: true === goal.strict};
                break;
            }
            case 'capture': {
                const targets = squares(goal.targets, `${where}: "targets"`);
                if ('string' === typeof targets) {
                    return targets;
                }
                parsedGoal = {type: 'capture', targets};
                break;
            }
            case 'script': {
                const steps = parseScript(goal.steps, where);
                if ('string' === typeof steps) {
                    return steps;
                }
                parsedGoal = {type: 'script', steps};
                break;
            }
            default:
                return `${where}: goal.type is read, reach, capture or script`;
        }
        if ('read' !== parsedGoal.type && null === bytes) {
            return `${where}: an interactive lesson needs a "board"`;
        }

        lessons.push({
            id,
            track: item.track,
            title: item.title,
            text: 'string' === typeof item.text ? item.text : null,
            board: bytes,
            flipped: true === item.flipped,
            images: images as string[],
            notes: notes as string[],
            goal: parsedGoal,
        });
    }

    return lessons;
}

/** What an interactive lesson needs from the page. */
interface Stage {
    view: SVGBoardView;
    say(text: string): void;
    caption(text: string): void;
    /** The lesson was solved. */
    solved(): void;
}

/** One running interactive lesson: it receives the board's clicks and drags. */
interface Interactive {
    click(index: number): void;
    attempt(from: number, to: number): void;
    dispose(): void;
}

/**
 * `reach` and `capture`: the player moves the lone white piece (and may wander); the engine says where it can go, the
 * client moves the piece on the bytes and gives the turn back. Solved when the last star is visited / the last marked
 * piece is taken. A `strict` lesson (a forward-only piece) starts over when the next star can no longer be reached.
 */
class FreeMoves implements Interactive {
    private bytes: Uint8Array;
    private legal: Array<{from: number; to: number}> = [];
    private selected: number | null = null;
    private starIndex = 0;
    private remaining: Set<number>;
    private locked = true;
    private disposed = false;

    constructor(
        private readonly lesson: Lesson & {goal: {type: 'reach'} | {type: 'capture'}},
        private readonly stage: Stage,
        private readonly api: GameAPI,
    ) {
        this.bytes = lesson.board!.slice();
        this.remaining = new Set('capture' === lesson.goal.type ? lesson.goal.targets : []);
    }

    async start(): Promise<void> {
        await this.show(false);
    }

    dispose(): void {
        this.disposed = true;
    }

    private get nextStar(): number | null {
        const goal = this.lesson.goal;

        return 'reach' === goal.type ? goal.stars[this.starIndex] ?? null : null;
    }

    private async show(animate: boolean): Promise<void> {
        this.locked = true;
        this.selected = null;
        await this.stage.view.render(this.bytes, this.lesson.flipped);
        if (animate) {
            await this.stage.view.whenSettled();
        }
        if (this.disposed) {
            return;
        }
        this.markPieces();
        const board = decodeBoardFromBinary(this.bytes);
        this.legal = (await this.api.getPotentialMoves(board)).map((move) => ({from: move.from, to: move.to}));
        if (this.disposed) {
            return;
        }
        this.overlays([]);
        this.locked = false;
    }

    /** The enemy pieces to take stand out. */
    private markPieces(): void {
        const styles = new Map<number, PieceStyle>();
        for (const target of this.remaining) {
            styles.set(target, 'highlighted');
        }
        this.stage.view.setPieceStyles(styles);
    }

    private overlays(potential: number[]): void {
        const highlights: TileHighlight[] = [];
        if (null !== this.selected) {
            highlights.push({position: this.selected, type: 'selected'});
        }
        for (const to of potential) {
            highlights.push({position: to, type: 'potential'});
        }
        // The star is drawn last so that it stays visible among the green squares.
        const star = this.nextStar;
        if (null !== star) {
            highlights.push({position: star, type: 'hovered'});
        }
        this.stage.view.updateOverlays(highlights);
    }

    private destinations(from: number): number[] {
        return this.legal.filter((move) => move.from === from).map((move) => move.to);
    }

    click(index: number): void {
        if (this.locked) {
            return;
        }
        if (null !== this.selected && this.destinations(this.selected).includes(index)) {
            void this.move(this.selected, index);

            return;
        }
        if (this.destinations(index).length > 0 && index !== this.selected) {
            this.selected = index;
            this.stage.say('');
            this.overlays(this.destinations(index));

            return;
        }
        this.selected = null;
        this.overlays([]);
    }

    attempt(from: number, to: number): void {
        if (!this.locked && this.destinations(from).includes(to)) {
            void this.move(from, to);

            return;
        }
        this.selected = null;
        this.overlays([]);
    }

    private async move(from: number, to: number): Promise<void> {
        this.bytes[to] = this.bytes[from];
        this.bytes[from] = 0;
        this.bytes[FLAGS_INDEX] = WHITE_TO_MOVE;
        this.bytes[FLAGS_INDEX + 1] = 0;
        this.remaining.delete(to);
        if (to === this.nextStar) {
            this.starIndex++;
        }
        this.stage.say('');
        await this.show(true);
        if (this.disposed) {
            return;
        }

        const goal = this.lesson.goal;
        if ('reach' === goal.type ? null === this.nextStar : 0 === this.remaining.size) {
            this.locked = true;
            this.stage.say(t('tutorial.lesson.done'));
            this.stage.solved();

            return;
        }
        const star = this.nextStar;
        if ('reach' === goal.type && goal.strict && null !== star && !this.legal.some((legalMove) => legalMove.to === star)) {
            this.locked = true;
            this.stage.say(t('tutorial.lesson.start_over'));
            await new Promise<void>((resolve) => window.setTimeout(resolve, RESTART_DELAY_MS));
            if (this.disposed) {
                return;
            }
            this.bytes = this.lesson.board!.slice();
            this.starIndex = 0;
            this.stage.say('');
            await this.show(true);
        }
    }
}

/** `script`: the player makes the recorded moves, the opponent's replies play themselves. */
class Script implements Interactive {
    private stepIndex = -1;
    private selected: number | null = null;
    private locked = true;
    private disposed = false;

    constructor(
        private readonly lesson: Lesson & {goal: {type: 'script'}},
        private readonly stage: Stage,
    ) {
    }

    async start(): Promise<void> {
        await this.stage.view.render(this.lesson.board!, this.lesson.flipped);
        await this.next();
    }

    dispose(): void {
        this.disposed = true;
    }

    private get step(): ScriptStep | undefined {
        return this.lesson.goal.steps[this.stepIndex];
    }

    /** Shows the next step's sentence and either waits for the player or plays the reply. */
    private async next(): Promise<void> {
        this.stepIndex++;
        this.selected = null;
        this.stage.say('');
        this.stage.view.updateOverlays([]);

        const step = this.step;
        if (!step) {
            this.locked = true;
            this.stage.say(t('tutorial.lesson.done'));
            this.stage.solved();

            return;
        }

        this.stage.caption(t(step.text));

        if ('player' === step.by) {
            this.locked = false;

            return;
        }

        this.locked = true;
        await new Promise<void>((resolve) => window.setTimeout(resolve, OPPONENT_DELAY_MS));
        if (!this.disposed) {
            await this.play(step);
        }
    }

    private async play(step: ScriptStep): Promise<void> {
        this.locked = true;
        this.stage.view.updateOverlays([]);
        await this.stage.view.render(step.board, this.lesson.flipped);
        await this.stage.view.whenSettled();
        if (!this.disposed) {
            await this.next();
        }
    }

    /** The player made the recorded move; a step with a `prompt` first asks the real game's question (same dialog). */
    private async answer(step: ScriptStep): Promise<void> {
        if (null === step.prompt) {
            await this.play(step);

            return;
        }

        this.locked = true;
        const choice = await askUnstack(PROMPTS[step.prompt]);
        if (choice === (step.unstack ? 'unstack' : 'stack')) {
            await this.play(step);

            return;
        }

        this.selected = null;
        this.stage.view.updateOverlays([]);
        this.stage.say(null === choice ? '' : t('tutorial.lesson.try_again'));
        this.locked = false;
    }

    click(index: number): void {
        const step = this.step;
        if (this.locked || !step) {
            return;
        }

        if (null === this.selected) {
            if (index !== step.from) {
                this.stage.say(t('tutorial.lesson.try_again'));

                return;
            }
            this.selected = index;
            this.stage.say('');
            this.stage.view.updateOverlays([{position: step.from, type: 'selected'}, {position: step.to, type: 'potential'}]);

            return;
        }

        if (index === step.to) {
            void this.answer(step);

            return;
        }

        // Clicking the selected piece again puts it back; anything else is a wrong answer
        this.selected = null;
        this.stage.view.updateOverlays([]);
        this.stage.say(index === step.from ? '' : t('tutorial.lesson.try_again'));
    }

    attempt(from: number, to: number): void {
        const step = this.step;
        if (this.locked || !step) {
            return;
        }

        if (from === step.from && to === step.to) {
            void this.answer(step);

            return;
        }

        this.selected = null;
        this.stage.view.updateOverlays([]);
        this.stage.say(t('tutorial.lesson.try_again'));
    }
}

/** The page: progress dots, the current lesson, Previous / Next / Skip, the switch of track and the end card. */
class Tutorial {
    private readonly gameState = new GameState();
    private readonly view = new SVGBoardView(this.gameState);
    private viewReady = false;
    private pointerDown = false;
    private readonly api = new GameAPI();

    private track: Track;
    private lessons: Lesson[] = [];
    private index = 0;
    private current: Interactive | null = null;
    private solved = false;
    private finished = false;
    /** Bumped on every lesson change: a late asynchronous step of a left lesson must not touch the page. */
    private generation = 0;

    private readonly dots = element<HTMLElement>('tutorial-dots');
    private readonly title = element<HTMLElement>('tutorial-title');
    private readonly captionEl = element<HTMLElement>('tutorial-caption');
    private readonly images = element<HTMLElement>('tutorial-images');
    private readonly boardEl = element<HTMLElement>('tutorial-board');
    private readonly notes = element<HTMLElement>('tutorial-notes');
    private readonly message = element<HTMLElement>('tutorial-message');
    private readonly previous = element<HTMLButtonElement>('tutorial-prev');
    private readonly next = element<HTMLButtonElement>('tutorial-next');
    private readonly switchButton = element<HTMLButtonElement>('tutorial-switch');
    private readonly nextLabel: string;

    constructor(private readonly all: Lesson[], initialTrack: Track) {
        this.track = initialTrack;
        this.nextLabel = this.next.textContent ?? '';
        this.previous.addEventListener('click', () => this.go(this.index - 1));
        this.next.addEventListener('click', () => this.forward());
        element('tutorial-skip').addEventListener('click', () => this.skipToGame());
        this.switchButton.addEventListener('click', () => this.setTrack('chess' === this.track ? 'beginner' : 'chess'));
        window.addEventListener('keres:experience', (event) => {
            const answer = (event as CustomEvent<string>).detail;
            if ('player' === answer || 'beginner' === answer) {
                this.setTrack('player' === answer ? 'chess' : 'beginner');
            }
        });
    }

    start(): void {
        this.setTrack(this.track);
    }

    private setTrack(track: Track): void {
        this.track = track;
        this.lessons = this.all.filter((lesson) => lesson.track === track);
        this.switchButton.textContent = this.switchButton.dataset['chess' === track ? 'labelBeginner' : 'labelChess'] ?? '';
        this.finished = false;
        element('tutorial-lessons').hidden = false;
        element('tutorial-end').hidden = true;
        this.go(0);
    }

    private forward(): void {
        if (this.index >= this.lessons.length - 1) {
            this.finish();

            return;
        }
        this.go(this.index + 1);
    }

    private async go(index: number): Promise<void> {
        if (index < 0 || index >= this.lessons.length) {
            return;
        }
        const generation = ++this.generation;
        this.current?.dispose();
        this.current = null;
        this.index = index;
        this.solved = false;
        const lesson = this.lessons[index];

        this.renderChrome(lesson);
        this.say('');

        if ('read' !== lesson.goal.type || null !== lesson.board) {
            await this.ensureView();
            if (generation !== this.generation) {
                return;
            }
        }
        this.boardEl.hidden = null === lesson.board;
        this.view.setPieceStyles(new Map());
        this.view.updateOverlays([]);

        if (null === lesson.board) {
            return;
        }
        if ('read' === lesson.goal.type) {
            await this.view.render(lesson.board, lesson.flipped);
            this.solved = true;
            this.refreshButtons();

            return;
        }

        const stage: Stage = {
            view: this.view,
            say: (text) => {
                if (generation === this.generation) {
                    this.say(text);
                }
            },
            caption: (text) => {
                if (generation === this.generation) {
                    this.captionEl.textContent = text;
                }
            },
            solved: () => {
                if (generation === this.generation) {
                    this.solved = true;
                    this.refreshButtons();
                }
            },
        };
        let interactive: FreeMoves | Script;
        if ('script' === lesson.goal.type) {
            interactive = new Script(lesson as Lesson & {goal: {type: 'script'}}, stage);
        } else {
            interactive = new FreeMoves(lesson as Lesson & {goal: {type: 'reach'} | {type: 'capture'}}, stage, this.api);
        }
        this.current = interactive;
        await interactive.start();
    }

    private renderChrome(lesson: Lesson): void {
        this.dots.replaceChildren(...this.lessons.map((other, i) => {
            const dot = document.createElement('li');
            dot.className = `h-2.5 w-2.5 rounded-full ${i === this.index ? 'bg-primary' : (i < this.index ? 'bg-primary/50' : 'bg-line')}`;
            dot.setAttribute('aria-hidden', 'true');

            return dot;
        }));
        this.dots.setAttribute('aria-label', t('tutorial.progress', {current: this.index + 1, total: this.lessons.length}));

        this.title.textContent = t(lesson.title);
        this.captionEl.textContent = null === lesson.text ? '' : t(lesson.text);
        this.images.replaceChildren(...lesson.images.map((path) => {
            const image = document.createElement('img');
            image.src = `/${path}`;
            image.alt = '';
            image.className = 'w-full rounded-md';
            image.loading = 'lazy';

            return image;
        }));
        this.notes.replaceChildren(...lesson.notes.map((key) => {
            const item = document.createElement('li');
            item.textContent = t(key);

            return item;
        }));
        this.refreshButtons();
    }

    private refreshButtons(): void {
        const last = this.index === this.lessons.length - 1;
        const interactive = 'read' !== this.lessons[this.index].goal.type;
        this.previous.disabled = 0 === this.index;
        this.next.textContent = last ? t('tutorial.nav.finish') : (interactive && !this.solved ? t('tutorial.nav.skip_lesson') : this.nextLabel);
    }

    private say(text: string): void {
        this.message.textContent = text;
    }

    /** One view for the whole page, created when the first lesson with a board needs it. */
    private async ensureView(): Promise<void> {
        if (this.viewReady) {
            return;
        }
        this.viewReady = true;
        // The container is revealed first: the view measures it.
        this.boardEl.hidden = false;
        await this.view.initialize(this.boardEl);
        this.view.setAnimationSpeed('slow');
        this.view.setCoordinatesVisible(true);

        this.view.onTileClick((index) => {
            if (!this.pointerDown) {
                this.current?.click(index);
            }
        });
        this.view.onDragMove((from, to) => {
            // Moving the dragged piece's element before the browser dispatches the click would swallow the next real
            // click (see the board editor): answer from the next task.
            window.setTimeout(() => this.current?.attempt(from, to), 0);
        });
        // The view also calls the click callback when a drag starts or is cancelled (it "selects" the source tile);
        // those happen with the button down, a real click is reported after it is up (same guard as the board editor).
        this.boardEl.addEventListener('mousedown', (event) => {
            if (0 === event.button) {
                this.pointerDown = true;
            }
        });
        this.boardEl.addEventListener('touchstart', () => {
            this.pointerDown = true;
        }, {passive: true});
        const release = (): void => {
            this.pointerDown = false;
        };
        window.addEventListener('mouseup', release);
        window.addEventListener('touchend', release);
        window.addEventListener('touchcancel', release);
    }

    /** Finishing the last lesson: the visitor has been through the tutorial; the ending offers the game. */
    private finish(): void {
        if (this.finished) {
            return;
        }
        this.finished = true;
        this.generation++;
        this.current?.dispose();
        void fetch('/onboarding/complete', {method: 'POST'}).catch(() => undefined);
        element('tutorial-lessons').hidden = true;
        const end = element('tutorial-end');
        end.hidden = false;
        end.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    /** "Skip to the game": the tutorial counts as done, and the game of the ending starts. */
    private skipToGame(): void {
        void fetch('/onboarding/complete', {method: 'POST'})
            .catch(() => undefined)
            .then(() => {
                const form = document.getElementById('tutorial-play-form');
                if (form instanceof HTMLFormElement) {
                    form.requestSubmit();
                } else {
                    this.finish();
                }
            });
    }
}

async function run(root: HTMLElement): Promise<void> {
    try {
        const response = await fetch(root.dataset.lessonsUrl ?? '');
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const lessons = parseLessons(await response.json());
        if ('string' === typeof lessons) {
            throw new Error(lessons);
        }

        new Tutorial(lessons, 'chess' === root.dataset.track ? 'chess' : 'beginner').start();
    } catch (error) {
        console.error('Tutorial lessons:', error);
        element('tutorial-caption').textContent = t('tutorial.lesson.invalid');
        // Never leave the visitor stuck on a broken file
        element('tutorial-end').hidden = false;
    }
}

const root = document.getElementById('tutorial');
if (root) {
    void run(root);
}
