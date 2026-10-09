import {decodePotentialMove, posToAlgebraic} from '../utils/boardUtils';
import SVGBoardView from '../views/SVGBoardView';
import {GameState} from '../models/GameState';
import {formatEvaluation} from '../views/EvalBar';

interface OpeningChild {
    moveId: number;
    /** Engine evaluation after this move (White's point of view, engine units); null = not computed yet. */
    evaluation: number | null;
    moveData: string; // base64, 2 bytes — opaque wire format, see boardUtils
    toBoardPositionId: number;
    toBoardPositionData: string; // base64, 81 bytes (square layout only, no flags)
    popularity: number;
}

interface OpeningStats {
    win: number;
    lose: number;
    draw: number;
    inProgress: number;
}

interface TreeResponse {
    children: OpeningChild[];
}

interface StatsResponse {
    children: OpeningChild[];
    stats: OpeningStats;
}

const MAX_TREE_DEPTH = 4;

function base64ToBytes(base64: string): Uint8Array {
    const binaryString = atob(base64);
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
    }
    return bytes;
}

/**
 * BoardPosition rows only persist the 81-byte square layout (globally
 * deduplicated across games) — the flags/move-counter bytes are per-Game
 * state, not per-position. Pad with zero flags so the shared SVGBoardView
 * renderer (which expects the full 83-byte wire format) can display piece
 * placement for debug purposes.
 */
function padBoardData(positionBytes: Uint8Array): Uint8Array {
    const padded = new Uint8Array(83);
    padded.set(positionBytes.subarray(0, 81), 0);
    return padded;
}

function moveLabel(moveDataB64: string): string {
    const bytes = base64ToBytes(moveDataB64);
    const moveU16 = bytes[0] | (bytes[1] << 8);
    const potential = decodePotentialMove(moveU16);
    return `${posToAlgebraic(potential.from)} \u2192 ${posToAlgebraic(potential.to)}`;
}

async function fetchTree(positionId: number): Promise<OpeningChild[]> {
    const res = await fetch(`/admin/api/opening-tree?position=${positionId}`);
    if (!res.ok) {
        return [];
    }
    const data = (await res.json()) as TreeResponse;
    return data.children;
}

function evaluationTag(evaluation: number | null): string {
    return null === evaluation ? '\u2026' : formatEvaluation(evaluation);
}

const EVALUATION_POLL_MS = 3000;
const EVALUATION_POLL_ATTEMPTS = 100;

/**
 * Edges the backfill command has not reached yet are queued for the
 * evaluation worker by the first request; this asks again until the worker
 * has stored the verdict (or gives up), so the tree never waits on the engine.
 */
function watchEvaluation(moveId: number, onEvaluation: (evaluation: number) => void, attempt = 0): void {
    void (async () => {
        try {
            const res = await fetch(`/admin/api/move-evaluation?move=${moveId}`);
            if (!res.ok) {
                return;
            }
            const body = (await res.json()) as {evaluation: number | null; pending: boolean};
            if (null !== body.evaluation) {
                onEvaluation(body.evaluation);
            } else if (body.pending && attempt < EVALUATION_POLL_ATTEMPTS) {
                setTimeout(() => watchEvaluation(moveId, onEvaluation, attempt + 1), EVALUATION_POLL_MS);
            }
        } catch (error) {
            console.error('Evaluation failed:', error);
        }
    })();
}

async function fetchStats(positionId: number, ply: number): Promise<StatsResponse | null> {
    const res = await fetch(`/admin/api/opening-stats?position=${positionId}&ply=${ply}`);
    if (!res.ok) {
        return null;
    }
    return (await res.json()) as StatsResponse;
}

export async function initOpeningExplorer(): Promise<void> {
    const treeContainer = document.getElementById('opening-tree');
    if (!treeContainer) {
        return; // not on the opening explorer page
    }

    const rootPositionId = parseInt(treeContainer.dataset.rootPositionId ?? '0', 10);
    const rootPositionData = treeContainer.dataset.rootPositionData;
    if (!rootPositionId || !rootPositionData) {
        return;
    }

    const boardContainer = document.getElementById('opening-debug-board');
    const statsContainer = document.getElementById('opening-stats');
    const evalContainer = document.getElementById('opening-eval');

    let boardView: SVGBoardView | null = null;
    if (boardContainer) {
        boardView = new SVGBoardView(new GameState());
        await boardView.initialize(boardContainer);
    }

    async function showPosition(positionDataB64: string): Promise<void> {
        if (!boardView) {
            return;
        }
        await boardView.render(padBoardData(base64ToBytes(positionDataB64)), false);
    }

    function renderStats(stats: OpeningStats | null): void {
        if (!statsContainer) {
            return;
        }
        if (!stats) {
            statsContainer.innerHTML = '';
            return;
        }
        const total = stats.win + stats.lose + stats.draw + stats.inProgress;
        statsContainer.innerHTML = `
            <p class="heading">Continuations reaching this position</p>
            <div class="tags">
                <span class="tag is-success">Win ${stats.win}</span>
                <span class="tag is-danger">Loss ${stats.lose}</span>
                <span class="tag is-light">Draw ${stats.draw}</span>
                <span class="tag is-info">In progress ${stats.inProgress}</span>
                <span class="tag is-white">Total ${total}</span>
            </div>
        `;
    }

    function renderEvaluation(evaluation: number | null): void {
        if (!evalContainer) {
            return;
        }
        evalContainer.textContent = null === evaluation
            ? 'Engine evaluation: computing\u2026'
            : `Engine evaluation: ${formatEvaluation(evaluation)} (White\'s point of view)`;
    }

    function renderChildren(container: HTMLElement, children: OpeningChild[], depth: number): void {
        container.innerHTML = '';
        if (0 === children.length) {
            const empty = document.createElement('li');
            empty.className = 'has-text-grey-light is-size-7';
            empty.textContent = 'No recorded continuations.';
            container.appendChild(empty);
            return;
        }
        for (const child of children) {
            container.appendChild(buildNode(child, depth));
        }
    }

    function buildNode(child: OpeningChild, depth: number): HTMLLIElement {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.href = '#';
        link.textContent = `${moveLabel(child.moveData)} (${child.popularity})`;
        li.appendChild(link);

        const evalTag = document.createElement('span');
        evalTag.className = 'tag is-light ml-2';
        evalTag.title = 'Engine evaluation (level 10), White\'s point of view';
        evalTag.textContent = evaluationTag(child.evaluation);
        li.appendChild(evalTag);

        if (null === child.evaluation) {
            watchEvaluation(child.moveId, (evaluation) => {
                child.evaluation = evaluation;
                evalTag.textContent = evaluationTag(evaluation);
            });
        }

        const childList = document.createElement('ul');
        childList.className = 'pl-4';
        childList.style.display = 'none';
        li.appendChild(childList);

        let expanded = false;

        link.addEventListener('click', (event: MouseEvent) => {
            event.preventDefault();
            void (async () => {
                await showPosition(child.toBoardPositionData);
                renderEvaluation(child.evaluation);

                if (depth >= MAX_TREE_DEPTH) {
                    // Leaf reached: fetch aggregate outcome stats plus one
                    // more level of continuations for further exploration.
                    const statsResponse = await fetchStats(child.toBoardPositionId, depth);
                    if (statsResponse) {
                        renderStats(statsResponse.stats);
                        if (!expanded) {
                            renderChildren(childList, statsResponse.children, depth + 1);
                            expanded = true;
                        }
                    }
                } else {
                    renderStats(null);
                    if (!expanded) {
                        const children = await fetchTree(child.toBoardPositionId);
                        renderChildren(childList, children, depth + 1);
                        expanded = true;
                    }
                }

                childList.style.display = 'none' === childList.style.display ? 'block' : 'none';
            })();
        });

        return li;
    }

    await showPosition(rootPositionData);
    const rootChildren = await fetchTree(rootPositionId);
    const rootList = document.createElement('ul');
    rootList.className = 'opening-tree-root';
    renderChildren(rootList, rootChildren, 1);
    treeContainer.innerHTML = '';
    treeContainer.appendChild(rootList);
}
