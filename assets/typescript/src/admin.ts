import {Chart, registerables} from 'chart.js';
import {initOpeningExplorer} from './admin/openingExplorer';
import {initBoardEditor} from './admin/boardEditor';

Chart.register(...registerables);

/** A design-system colour token (`--k-*` holds an RGB triplet) as a CSS colour Chart.js understands. */
function token(name: string): string {
    const triplet = getComputedStyle(document.documentElement).getPropertyValue(`--k-${name}`).trim().split(/\s+/);

    return `rgb(${triplet.join(', ')})`;
}

interface AdminStats {
    outcomes?: {win: number; lose: number; draw: number};
    moveCountDistribution?: Record<string, number>;
}

function initDashboardCharts(): void {
    const statsEl = document.getElementById('admin-stats');
    if (!statsEl) {
        return; // not on the dashboard page
    }
    const stats = JSON.parse(statsEl.textContent ?? '{}') as AdminStats;
    // Dark surfaces: Chart.js defaults (grey text, light grid) are made for white ones.
    Chart.defaults.color = token('muted');
    Chart.defaults.borderColor = token('edge');

    const outcomeCanvas = document.getElementById('admin-outcome-chart') as HTMLCanvasElement | null;
    if (outcomeCanvas && stats.outcomes) {
        new Chart(outcomeCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Wins', 'Losses', 'Draws'],
                datasets: [
                    {
                        data: [stats.outcomes.win, stats.outcomes.lose, stats.outcomes.draw],
                        backgroundColor: [token('success'), token('danger-text'), token('muted')],
                        borderColor: token('surface'),
                    },
                ],
            },
        });
    }

    const moveCountCanvas = document.getElementById('admin-move-count-chart') as HTMLCanvasElement | null;
    if (moveCountCanvas && stats.moveCountDistribution) {
        new Chart(moveCountCanvas, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.moveCountDistribution),
                datasets: [
                    {
                        label: 'Games',
                        data: Object.values(stats.moveCountDistribution),
                        backgroundColor: token('info'),
                    },
                ],
            },
            options: {
                scales: {y: {beginAtZero: true, ticks: {precision: 0}}},
            },
        });
    }
}

initDashboardCharts();
void initOpeningExplorer();
void initBoardEditor();
